<?php
namespace DesignCoreHub\Gutenberg;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BlueprintCompiler {
    public static function compile( array $blueprint ): array {
        $errors = array(); $blocks = isset($blueprint['blocks'])&&is_array($blueprint['blocks'])?$blueprint['blocks']:array();
        if ( ! $blocks ) $errors[] = 'Blueprint must contain a non-empty blocks array.';
        $content = '';
        foreach ( $blocks as $index => $node ) { if (!is_array($node)) { $errors[] = sprintf('Block at index %d must be an object.',$index); continue; } $content .= self::compile_node($node,$errors,'blocks['.$index.']'); }
        $css = isset($blueprint['css'])&&is_string($blueprint['css'])?self::sanitize_css($blueprint['css']):'';
        return array('valid'=>empty($errors),'errors'=>$errors,'content'=>trim($content),'css'=>$css);
    }

    private static function compile_node( array $node, array &$errors, string $path ): string {
        $type = isset($node['type'])?sanitize_key($node['type']):'';
        switch($type){
            case 'group': return self::container('group','div','wp-block-group',$node,$errors,$path);
            case 'columns': return self::container('columns','div','wp-block-columns',$node,$errors,$path);
            case 'column': return self::container('column','div','wp-block-column',$node,$errors,$path);
            case 'buttons': return self::container('buttons','div','wp-block-buttons',$node,$errors,$path);
            case 'heading': return self::heading($node);
            case 'paragraph': return self::paragraph($node);
            case 'button': return self::button($node);
            case 'image': return self::image($node);
            case 'spacer': return self::spacer($node);
            case 'separator': return self::separator($node);
            case 'list': return self::list_block($node);
            case 'quote': return self::quote($node);
            default: $errors[] = sprintf('%s uses unsupported blueprint type "%s".',$path,$type?:'(empty)'); return '';
        }
    }

    private static function container(string $block,string $tag,string $base,array $node,array &$errors,string $path): string {
        $attrs=self::common_attrs($node); $class=self::class_string($base,$node); $inner=''; $children=isset($node['children'])&&is_array($node['children'])?$node['children']:array();
        foreach($children as $index=>$child){ if(!is_array($child)){ $errors[]=sprintf('%s.children[%d] must be an object.',$path,$index); continue;} $inner.=self::compile_node($child,$errors,$path.'.children['.$index.']'); }
        return self::open_comment($block,$attrs).sprintf('<%1$s class="%2$s">',$tag,esc_attr($class)).$inner.sprintf('</%s>',$tag).self::close_comment($block);
    }

    private static function heading(array $node): string { $level=min(6,max(1,isset($node['level'])?absint($node['level']):2)); $attrs=self::common_attrs($node); if(2!==$level)$attrs['level']=$level; $text=self::inline_content($node['text']??''); $class=self::class_string('wp-block-heading',$node); return self::open_comment('heading',$attrs).sprintf('<h%1$d class="%2$s">%3$s</h%1$d>',$level,esc_attr($class),$text).self::close_comment('heading'); }
    private static function paragraph(array $node): string { $attrs=self::common_attrs($node); $text=self::inline_content($node['text']??''); $class=self::class_string('',$node); $attr=$class?' class="'.esc_attr($class).'"':''; return self::open_comment('paragraph',$attrs).'<p'.$attr.'>'.$text.'</p>'.self::close_comment('paragraph'); }
    private static function button(array $node): string { $attrs=self::common_attrs($node); $class=self::class_string('wp-block-button',$node); $text=self::inline_content($node['text']??'Button'); $url=isset($node['url'])?esc_url($node['url']):''; $target=!empty($node['newTab'])?' target="_blank" rel="noopener"':''; return self::open_comment('button',$attrs).'<div class="'.esc_attr($class).'"><a class="wp-block-button__link wp-element-button" href="'.esc_url($url).'"'.$target.'>'.$text.'</a></div>'.self::close_comment('button'); }
    private static function image(array $node): string { $attrs=self::common_attrs($node); $id=isset($node['id'])?absint($node['id']):0; $url=isset($node['url'])?esc_url($node['url']):''; $alt=isset($node['alt'])?sanitize_text_field($node['alt']):''; $size=isset($node['size'])?sanitize_key($node['size']):'full'; if($id)$attrs['id']=$id; if($size){$attrs['sizeSlug']=$size;$attrs['linkDestination']='none';} $class=self::class_string('wp-block-image size-'.$size,$node); $img_class=$id?' class="wp-image-'.$id.'"':''; return self::open_comment('image',$attrs).'<figure class="'.esc_attr($class).'"><img src="'.esc_url($url).'" alt="'.esc_attr($alt).'"'.$img_class.'/></figure>'.self::close_comment('image'); }
    private static function spacer(array $node): string { $height=isset($node['height'])?sanitize_text_field($node['height']):'40px'; $attrs=array('height'=>$height); if(!empty($node['className']))$attrs['className']=self::sanitize_classes((string)$node['className']); $class=self::class_string('wp-block-spacer',$node); return self::open_comment('spacer',$attrs).'<div style="height:'.esc_attr($height).'" aria-hidden="true" class="'.esc_attr($class).'"></div>'.self::close_comment('spacer'); }
    private static function separator(array $node): string { $attrs=self::common_attrs($node); $class=self::class_string('wp-block-separator has-alpha-channel-opacity',$node); return self::open_comment('separator',$attrs).'<hr class="'.esc_attr($class).'"/>'.self::close_comment('separator'); }
    private static function list_block(array $node): string { $ordered=!empty($node['ordered']); $attrs=self::common_attrs($node); if($ordered)$attrs['ordered']=true; $tag=$ordered?'ol':'ul'; $class=self::class_string('',$node); $items=isset($node['items'])&&is_array($node['items'])?$node['items']:array(); $inner=''; foreach($items as $item)$inner.='<li>'.self::inline_content($item).'</li>'; $class_attr=$class?' class="'.esc_attr($class).'"':''; return self::open_comment('list',$attrs).'<'.$tag.$class_attr.'>'.$inner.'</'.$tag.'>'.self::close_comment('list'); }
    private static function quote(array $node): string { $attrs=self::common_attrs($node); $class=self::class_string('wp-block-quote',$node); $text=self::inline_content($node['text']??''); $cite=self::inline_content($node['cite']??''); return self::open_comment('quote',$attrs).'<blockquote class="'.esc_attr($class).'"><p>'.$text.'</p>'.($cite?'<cite>'.$cite.'</cite>':'').'</blockquote>'.self::close_comment('quote'); }
    private static function common_attrs(array $node): array { $attrs=array(); if(!empty($node['className']))$attrs['className']=self::sanitize_classes((string)$node['className']); if(!empty($node['anchor']))$attrs['anchor']=sanitize_title((string)$node['anchor']); return $attrs; }
    private static function class_string(string $base,array $node): string { $classes=array_filter(preg_split('/\s+/',trim($base.' '.(string)($node['className']??'')))); $classes=array_map('sanitize_html_class',$classes); return implode(' ',array_filter(array_unique($classes))); }
    private static function sanitize_classes(string $classes): string { return implode(' ',array_filter(array_map('sanitize_html_class',array_filter(preg_split('/\s+/',trim($classes)))))); }
    private static function inline_content($value): string { if(!is_scalar($value))return ''; return wp_kses((string)$value,array('br'=>array(),'strong'=>array(),'em'=>array(),'mark'=>array(),'span'=>array('class'=>true))); }
    private static function open_comment(string $name,array $attrs): string { $json=$attrs?' '.wp_json_encode($attrs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):''; return '<!-- wp:'.$name.$json.' -->'; }
    private static function close_comment(string $name): string { return '<!-- /wp:'.$name.' -->'; }
    public static function sanitize_css(string $css): string { $css=str_replace("\0",'',$css); $css=preg_replace('#</?style[^>]*>#i','',$css); return trim((string)$css); }
}
