<?php
namespace DesignCoreHub\Rest;

use DesignCoreHub\Gutenberg\BlueprintCompiler;
use DesignCoreHub\Gutenberg\Validator;
use DesignCoreHub\History\Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Controller {
    private const NS = 'design-core-hub/v1';

    public static function register_routes(): void {
        $read = array('permission_callback'=>array(self::class,'can_build'));
        register_rest_route(self::NS,'/context',array_merge($read,array('methods'=>'GET','callback'=>array(self::class,'context'))));
        register_rest_route(self::NS,'/design-system',array_merge($read,array('methods'=>'GET','callback'=>array(self::class,'design_system'))));
        register_rest_route(self::NS,'/blocks',array_merge($read,array('methods'=>'GET','callback'=>array(self::class,'blocks'))));
        register_rest_route(self::NS,'/pages',array_merge($read,array('methods'=>'GET','callback'=>array(self::class,'pages'))));
        register_rest_route(self::NS,'/drafts',array_merge($read,array('methods'=>'POST','callback'=>array(self::class,'create_draft'))));
        register_rest_route(self::NS,'/compile',array_merge($read,array('methods'=>'POST','callback'=>array(self::class,'compile'))));
        register_rest_route(self::NS,'/validate',array_merge($read,array('methods'=>'POST','callback'=>array(self::class,'validate'))));
        register_rest_route(self::NS,'/references',array_merge($read,array('methods'=>'POST','callback'=>array(self::class,'create_reference'))));
        $edit=array('permission_callback'=>array(self::class,'can_edit_page'));
        register_rest_route(self::NS,'/pages/(?P<id>\\d+)',array_merge($edit,array('methods'=>'GET','callback'=>array(self::class,'page'))));
        register_rest_route(self::NS,'/pages/(?P<id>\\d+)/apply',array_merge($edit,array('methods'=>'POST','callback'=>array(self::class,'apply'))));
        register_rest_route(self::NS,'/pages/(?P<id>\\d+)/history',array_merge($edit,array('methods'=>'GET','callback'=>array(self::class,'history'))));
        register_rest_route(self::NS,'/pages/(?P<id>\\d+)/rollback',array_merge($edit,array('methods'=>'POST','callback'=>array(self::class,'rollback'))));
    }

    public static function can_build(): bool { return current_user_can(DCH_CAPABILITY) && current_user_can('edit_pages'); }
    public static function can_edit_page(WP_REST_Request $r): bool { $id=absint($r['id']); return self::can_build() && $id && current_user_can('edit_post',$id); }

    public static function context(): WP_REST_Response {
        $theme=wp_get_theme(); $types=\WP_Block_Type_Registry::get_instance()->get_all_registered();
        return self::ok(array('plugin_version'=>DCH_VERSION,'wordpress'=>get_bloginfo('version'),'site_name'=>get_bloginfo('name'),'site_url'=>home_url('/'),'admin_url'=>admin_url(),'theme'=>array('name'=>$theme->get('Name'),'stylesheet'=>$theme->get_stylesheet(),'version'=>$theme->get('Version'),'block_theme'=>function_exists('wp_is_block_theme')?wp_is_block_theme():false),'registered_blocks'=>count($types),'workflow'=>array('write_target'=>'draft_pages_only','css_scope'=>'page_only','mcp_required'=>false,'browser_ui'=>true)));
    }

    public static function design_system(): WP_REST_Response {
        return self::ok(array('settings'=>function_exists('wp_get_global_settings')?wp_get_global_settings():array(),'styles'=>function_exists('wp_get_global_styles')?wp_get_global_styles():array()));
    }

    public static function blocks(WP_REST_Request $r): WP_REST_Response {
        $search=strtolower(sanitize_text_field((string)$r->get_param('search'))); $limit=min(200,max(1,absint($r->get_param('limit')?:80))); $result=array();
        foreach(\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name=>$type){
            if($search && false===strpos(strtolower($name.' '.(string)$type->title.' '.(string)$type->description),$search)) continue;
            $result[]=array('name'=>$name,'title'=>$type->title,'description'=>$type->description,'category'=>$type->category,'parent'=>$type->parent,'ancestor'=>$type->ancestor,'attributes'=>$type->attributes,'supports'=>$type->supports,'dynamic'=>is_callable($type->render_callback));
            if(count($result)>=$limit) break;
        }
        return self::ok(array('blocks'=>$result,'count'=>count($result)));
    }

    public static function pages(WP_REST_Request $r): WP_REST_Response {
        $q=new \WP_Query(array('post_type'=>'page','post_status'=>array('draft','pending','private','publish'),'posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC','s'=>sanitize_text_field((string)$r->get_param('search'))));
        return self::ok(array('pages'=>array_map(array(self::class,'page_summary'),$q->posts)));
    }

    public static function page(WP_REST_Request $r) {
        $post=get_post(absint($r['id'])); if(!$post||'page'!==$post->post_type) return self::error('not_found','Page not found.',404);
        return self::ok(array_merge(self::page_summary($post),array('content'=>(string)$post->post_content,'css'=>(string)get_post_meta($post->ID,'_dch_page_css',true),'managed'=>(bool)get_post_meta($post->ID,'_dch_managed',true),'validation'=>Validator::validate((string)$post->post_content))));
    }

    public static function create_draft(WP_REST_Request $r) {
        $b=self::json($r); $title=sanitize_text_field((string)($b['title']??'')); if(''===$title)return self::error('missing_title','A page title is required.',400);
        $id=wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>$title,'post_name'=>sanitize_title((string)($b['slug']??'')),'post_content'=>'','comment_status'=>'closed','ping_status'=>'closed'),true); if(is_wp_error($id))return $id;
        update_post_meta($id,'_dch_managed',1); return self::ok(self::page_summary(get_post($id)),201);
    }

    public static function compile(WP_REST_Request $r): WP_REST_Response {
        $b=self::json($r); $blueprint=isset($b['blueprint'])&&is_array($b['blueprint'])?$b['blueprint']:$b; $out=BlueprintCompiler::compile($blueprint);
        if($out['valid']){$out['validation']=Validator::validate($out['content']);$out['valid']=$out['validation']['valid'];}
        return self::ok($out);
    }

    public static function validate(WP_REST_Request $r): WP_REST_Response { $b=self::json($r); return self::ok(Validator::validate(isset($b['content'])?(string)$b['content']:'')); }

    public static function apply(WP_REST_Request $r) {
        $id=absint($r['id']); $post=get_post($id); if(!$post||'page'!==$post->post_type)return self::error('not_found','Page not found.',404); if('draft'!==$post->post_status)return self::error('draft_only','Design Core Hub writes only to draft pages.',409);
        $b=self::json($r); $expected=sanitize_text_field((string)($b['expected_modified_gmt']??'')); if($expected&&$expected!==$post->post_modified_gmt)return self::error('revision_conflict','The draft changed since it was loaded. Reload it before applying.',409);
        $content=isset($b['content'])?(string)$b['content']:''; $validation=Validator::validate($content); if(!$validation['valid'])return self::error('invalid_build','Build validation failed.',422,$validation);
        Repository::snapshot($id,(string)($b['note']??'Before build'));
        $updated=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$content)),true); if(is_wp_error($updated))return $updated;
        update_post_meta($id,'_dch_page_css',BlueprintCompiler::sanitize_css(isset($b['css'])?(string)$b['css']:'')); update_post_meta($id,'_dch_managed',1); clean_post_cache($id);
        return self::ok(array('page'=>self::page_summary(get_post($id)),'validation'=>$validation));
    }

    public static function history(WP_REST_Request $r): WP_REST_Response {
        $items=array_map(static function($e){return array('id'=>$e['id']??'','created_gmt'=>$e['created_gmt']??'','user_id'=>$e['user_id']??0,'label'=>$e['label']??'','modified_gmt'=>$e['modified_gmt']??'');},Repository::all(absint($r['id']))); return self::ok(array('history'=>$items));
    }

    public static function rollback(WP_REST_Request $r) {
        $id=absint($r['id']); $post=get_post($id); if(!$post||'page'!==$post->post_type)return self::error('not_found','Page not found.',404); if('draft'!==$post->post_status)return self::error('draft_only','Rollback is limited to draft pages.',409);
        $b=self::json($r); $entry=Repository::find($id,sanitize_text_field((string)($b['entry_id']??''))); if(!$entry)return self::error('history_not_found','History entry not found.',404);
        Repository::snapshot($id,'Before rollback'); $updated=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>(string)($entry['post_content']??''))),true); if(is_wp_error($updated))return $updated;
        update_post_meta($id,'_dch_page_css',(string)($entry['page_css']??'')); update_post_meta($id,'_dch_managed',1); clean_post_cache($id); return self::ok(array('page'=>self::page_summary(get_post($id))));
    }

    public static function create_reference(WP_REST_Request $r) {
        $b=self::json($r); $html=isset($b['html'])?(string)$b['html']:''; if(''===trim($html))return self::error('empty_reference','Reference HTML is empty.',400); if(strlen($html)>1048576)return self::error('reference_too_large','Reference HTML exceeds the 1 MiB limit.',413);
        $token=wp_generate_uuid4(); set_transient('dch_ref_'.$token,array('user_id'=>get_current_user_id(),'name'=>sanitize_text_field((string)($b['name']??'Reference')),'html'=>$html),6*HOUR_IN_SECONDS);
        return self::ok(array('token'=>$token,'preview_url'=>add_query_arg(array('page'=>'design-core-hub-reference','ref'=>$token),admin_url('admin.php')),'expires_in'=>6*HOUR_IN_SECONDS),201);
    }

    public static function page_summary($post): array { return array('id'=>(int)$post->ID,'title'=>get_the_title($post),'slug'=>(string)$post->post_name,'status'=>(string)$post->post_status,'modified_gmt'=>(string)$post->post_modified_gmt,'edit_url'=>get_edit_post_link($post->ID,'raw'),'preview_url'=>get_preview_post_link($post),'permalink'=>get_permalink($post)); }
    private static function json(WP_REST_Request $r): array { $b=$r->get_json_params(); return is_array($b)?$b:array(); }
    private static function ok(array $data,int $status=200): WP_REST_Response { return new WP_REST_Response(array('success'=>true,'data'=>$data),$status); }
    private static function error(string $code,string $message,int $status,array $details=array()): WP_Error { return new WP_Error($code,$message,array('status'=>$status,'details'=>$details)); }
}
