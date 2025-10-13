<?php
/**
 * Plugin Name: Smart Image Optimizer — OnePack
 * Description: One plugin to generate WebP/AVIF (original + sizes), serve via <picture>, regenerate per-image, bulk-convert existing media, and add safe-upload options. Playground-friendly.
 * Version: 1.0.0
 * Author: You
 * License: GPL2+
 */

if (!defined('ABSPATH')) exit;

# ---------------------------
# Options & Helpers
# ---------------------------
function smartio_onepack_default_opts() {
    return array(
        'prefer_gd' => 1,
        'light_sizes' => 1,
        'big_image_max' => 2560,
        'playground_force_webp' => 1,
    );
}
function smartio_onepack_get_opts(){
    $opts = get_option('smartio_onepack_opts', array());
    $defaults = smartio_onepack_default_opts();
    $opts = is_array($opts) ? array_merge($defaults, $opts) : $defaults;
    // clamp
    $opts['big_image_max'] = max(1024, intval($opts['big_image_max']));
    $opts['prefer_gd'] = intval($opts['prefer_gd']) ? 1 : 0;
    $opts['light_sizes'] = intval($opts['light_sizes']) ? 1 : 0;
    $opts['playground_force_webp'] = intval($opts['playground_force_webp']) ? 1 : 0;
    return $opts;
}
function smartio_onepack_is_playground(){
    $d = wp_get_upload_dir();
    return (strpos($d['baseurl'], 'playground.wordpress.net') !== false);
}
function smartio_onepack_upload_dirs(){
    static $d = null;
    if ($d === null) $d = wp_get_upload_dir();
    return $d;
}
function smartio_onepack_url_to_path($url){
    $d = smartio_onepack_upload_dirs();
    return str_replace($d['baseurl'], $d['basedir'], $url);
}
function smartio_onepack_avif_supported(){
    return function_exists('imageavif');
}

# ---------------------------
# Safe Upload Tweaks
# ---------------------------
add_filter('wp_image_editors', function($editors){
    $opts = smartio_onepack_get_opts();
    if ($opts['prefer_gd']) {
        return array('WP_Image_Editor_GD', 'WP_Image_Editor_Imagick');
    }
    return $editors;
});
add_filter('big_image_size_threshold', function($threshold){
    $opts = smartio_onepack_get_opts();
    return intval($opts['big_image_max']);
});
add_filter('intermediate_image_sizes_advanced', function($sizes){
    $opts = smartio_onepack_get_opts();
    if ($opts['light_sizes']) {
        $keep = array('thumbnail','medium','large');
        return array_intersect_key($sizes, array_flip($keep));
    }
    return $sizes;
});

# ---------------------------
# Generation on Upload (original + all sizes)
# ---------------------------
add_filter('wp_generate_attachment_metadata', function($metadata, $attachment_id){
    $file = get_attached_file($attachment_id);
    $mime = get_post_mime_type($attachment_id);
    if (!in_array($mime, array('image/jpeg','image/png'))) return $metadata;

    smartio_onepack_generate($file, 'webp');
    smartio_onepack_generate($file, 'avif');

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])){
        $base_dir = trailingslashit(dirname($file));
        foreach ($metadata['sizes'] as $size){
            if (!empty($size['file'])){
                $p = $base_dir . $size['file'];
                smartio_onepack_generate($p, 'webp');
                smartio_onepack_generate($p, 'avif');
            }
        }
    }
    return $metadata;
}, 10, 2);

function smartio_onepack_generate($file, $format){
    $target = $file . '.' . $format;
    if (file_exists($target)) return;

    // Try WP editor first
    $editor = wp_get_image_editor($file);
    if (!is_wp_error($editor)) {
        $quality = ($format === 'webp') ? 82 : 60;
        $editor->set_quality($quality);
        try { $editor->save($target, $format); if (file_exists($target)) return; } catch (Exception $e) {}
    }

    // GD fallback
    $info = @getimagesize($file);
    if (!$info) return;
    $mime = isset($info['mime']) ? $info['mime'] : '';
    if ($mime === 'image/jpeg') { $img = @imagecreatefromjpeg($file); }
    elseif ($mime === 'image/png') { $img = @imagecreatefrompng($file); if ($img){ imagepalettetotruecolor($img); imagealphablending($img,false); imagesavealpha($img,true);} }
    else { $img = false; }
    if (!$img) return;

    if ($format === 'webp' && function_exists('imagewebp')) { @imagewebp($img, $target, 82); }
    elseif ($format === 'avif' && function_exists('imageavif')) { @imageavif($img, $target, 60); }
    imagedestroy($img);
}

# ---------------------------
# Front-end Delivery: <picture> for core/image
# ---------------------------
add_filter('render_block', function($block_content, $block){
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) return $block_content;
    if (empty($block['blockName']) || $block['blockName'] !== 'core/image') return $block_content;
    if (!preg_match('/<img[^>]+>/i', $block_content, $imgm)) return $block_content;

    $img_tag = $imgm[0];
    $src = smartio_onepack_attr($img_tag, 'src');
    $sizes_attr = smartio_onepack_attr($img_tag, 'sizes');
    $width_attr = intval(smartio_onepack_attr($img_tag, 'width'));

    $att_id = 0;
    if (!empty($block['attrs']['id'])) $att_id = intval($block['attrs']['id']);
    if (!$att_id && $src) $att_id = attachment_url_to_postid($src);

    $srcset_webp = '';
    $srcset_avif = '';
    $opts = smartio_onepack_get_opts();

    if ($att_id){
        $meta = wp_get_attachment_metadata($att_id);
        $base_url = trailingslashit(dirname(wp_get_attachment_url($att_id)));

        $cands = array();
        if (!empty($meta['sizes']) && is_array($meta['sizes'])){
            foreach ($meta['sizes'] as $s){
                if (!empty($s['file']) && !empty($s['width'])){
                    $cands[] = array($base_url . $s['file'], intval($s['width']));
                }
            }
        }
        $orig_w = !empty($meta['width']) ? intval($meta['width']) : ($width_attr ?: 1500);
        $cands[] = array(wp_get_attachment_url($att_id), $orig_w);

        $srcset_webp = smartio_onepack_build_srcset($cands, 'webp', $opts);
        if (smartio_onepack_avif_supported()) {
            $srcset_avif = smartio_onepack_build_srcset($cands, 'avif', $opts);
        }
    } else {
        $w = $width_attr ? $width_attr : 1500;
        $cands = array(array($src, $w));
        $srcset_webp = smartio_onepack_build_srcset($cands, 'webp', $opts);
        if (smartio_onepack_avif_supported()) {
            $srcset_avif = smartio_onepack_build_srcset($cands, 'avif', $opts);
        }
    }

    if (!$srcset_webp && !$srcset_avif) return $block_content;

    $sources = '';
    if ($srcset_avif) $sources .= '<source type="image/avif" srcset="'.esc_attr($srcset_avif).'"'.($sizes_attr?' sizes="'.esc_attr($sizes_attr).'"':'').' />';
    if ($srcset_webp) $sources .= '<source type="image/webp" srcset="'.esc_attr($srcset_webp).'"'.($sizes_attr?' sizes="'.esc_attr($sizes_attr).'"':'').' />';

    $picture = '<picture>'.$sources.$img_tag.'</picture>';
    return str_replace($img_tag, $picture, $block_content);
}, 9, 2);

function smartio_onepack_attr($tag, $name){
    if (preg_match('/\s'.preg_quote($name, '/').'=(["\'])(.*?)\1/i', $tag, $m)) return html_entity_decode($m[2], ENT_QUOTES);
    return '';
}

function smartio_onepack_build_srcset($cands, $ext, $opts){
    $entries = array();
    $assume_webp = (smartio_onepack_is_playground() && $ext === 'webp' && $opts['playground_force_webp']);

    foreach ($cands as $c){
        $url = $c[0];
        $w = intval($c[1]);
        if (!preg_match('/\.(jpe?g|png)$/i', $url)) continue;
        $modern_url = $url . '.' . $ext;
        if ($ext === 'webp' && $assume_webp){
            $entries[] = $modern_url . ' ' . $w . 'w';
        } else {
            $path = smartio_onepack_url_to_path($modern_url);
            if (file_exists($path)) $entries[] = $modern_url . ' ' . $w . 'w';
        }
    }
    return implode(', ', $entries);
}

# ---------------------------
# Per-image Regenerate Button
# ---------------------------
add_action('attachment_submitbox_misc_actions', function($post){
    if ($post->post_type !== 'attachment') return;
    $id = intval($post->ID);
    $url = wp_nonce_url(admin_url('admin-post.php?action=smartio_onepack_regen_one&attachment_id='.$id), 'smartio_onepack_regen_'.$id);
    echo '<div class="misc-pub-section"><a href="'.$url.'" class="button">Regenerate WebP (all sizes)</a></div>';
});

add_action('admin_post_smartio_onepack_regen_one', function(){
    if (!current_user_can('upload_files')) wp_die('No permission');
    $id = isset($_GET['attachment_id']) ? intval($_GET['attachment_id']) : 0;
    check_admin_referer('smartio_onepack_regen_'.$id);
    if (!$id) wp_die('Missing attachment_id');

    $mime = get_post_mime_type($id);
    if (!in_array($mime, array('image/jpeg','image/png'))) wp_die('Not a JPG/PNG');

    $file = get_attached_file($id);
    $count = 0;
    if ($file && file_exists($file)){
        smartio_onepack_generate($file, 'webp'); $count++;
        smartio_onepack_generate($file, 'avif');
    }
    $meta = wp_get_attachment_metadata($id);
    if (!empty($meta['sizes']) && is_array($meta['sizes'])){
        $base_dir = trailingslashit(dirname($file));
        foreach ($meta['sizes'] as $s){
            if (!empty($s['file'])){
                $p = $base_dir . $s['file'];
                if (file_exists($p)){
                    smartio_onepack_generate($p, 'webp'); $count++;
                    smartio_onepack_generate($p, 'avif');
                }
            }
        }
    }
    wp_safe_redirect(add_query_arg(array('smartio_onepack_regen_done'=>$count), get_edit_post_link($id, '')));
    exit;
});

# ---------------------------
# Bulk Convert (All Sizes)
# ---------------------------
add_action('admin_menu', function(){
    add_management_page('SmartIO Bulk WebP (All Sizes)', 'SmartIO Bulk WebP (All Sizes)', 'manage_options', 'smartio-onepack-bulk', 'smartio_onepack_bulk_render');
});

function smartio_onepack_bulk_render(){
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>SmartIO Bulk WebP (All Sizes)</h1>';
    if (!function_exists('imagewebp')){
        echo '<p style="color:#b00;">WebP encoder not available on this host.</p></div>'; return;
    }
    $limit  = isset($_GET['limit'])  ? max(10, intval($_GET['limit']))  : 30;
    $offset = isset($_GET['offset']) ? max(0,  intval($_GET['offset'])) : 0;

    if (isset($_POST['run'])){
        $converted = smartio_onepack_bulk_convert($limit, $offset);
        echo '<p><strong>Converted this run:</strong> ' . intval($converted) . '</p>';
        $next_offset = $offset + $limit;
        echo '<p><a class="button button-primary" href="'.admin_url('tools.php?page=smartio-onepack-bulk&limit='.$limit.'&offset='.$next_offset).'">Run Next Batch</a> ';
        echo '<a class="button" href="'.admin_url('tools.php?page=smartio-onepack-bulk').'">Start Over</a></p>';
    } else {
        echo '<form method="post"><p>Batch size: <input type="number" name="limit" value="'.esc_attr($limit).'" min="10" max="200"></p><p><button class="button button-primary" name="run" value="1">Run Batch</button></p></form>';
    }
    echo '<p>Tip: Re-run until it shows 0 converted to process your whole library.</p>';
    echo '</div>';
}

function smartio_onepack_bulk_convert($limit, $offset){
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'post_mime_type' => array('image/jpeg','image/png'),
        'fields'         => 'ids',
    );
    $q = new WP_Query($args);
    $count = 0;
    foreach ($q->posts as $attachment_id){
        $file = get_attached_file($attachment_id);
        if ($file && file_exists($file)){
            smartio_onepack_generate($file, 'webp'); $count++;
            smartio_onepack_generate($file, 'avif');
        }
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])){
            $base_dir = trailingslashit(dirname($file));
            foreach ($meta['sizes'] as $s){
                if (!empty($s['file'])){
                    $p = $base_dir . $s['file'];
                    if (file_exists($p)){
                        smartio_onepack_generate($p, 'webp'); $count++;
                        smartio_onepack_generate($p, 'avif');
                    }
                }
            }
        }
    }
    return $count;
}

# ---------------------------
# Settings Page
# ---------------------------
add_action('admin_menu', function(){
    add_options_page('Smart Image Optimizer — OnePack', 'Smart Image Optimizer', 'manage_options', 'smartio-onepack-settings', 'smartio_onepack_render_settings');
});
function smartio_onepack_render_settings(){
    if (!current_user_can('manage_options')) return;
    if (!empty($_POST['smartio_onepack_save'])){
        check_admin_referer('smartio_onepack_save');
        $opts = smartio_onepack_get_opts();
        $opts['prefer_gd'] = isset($_POST['prefer_gd']) ? 1 : 0;
        $opts['light_sizes'] = isset($_POST['light_sizes']) ? 1 : 0;
        $opts['big_image_max'] = isset($_POST['big_image_max']) ? max(1024, intval($_POST['big_image_max'])) : 2560;
        $opts['playground_force_webp'] = isset($_POST['playground_force_webp']) ? 1 : 0;
        update_option('smartio_onepack_opts', $opts);
        echo '<div class="updated"><p>Saved.</p></div>';
    }
    $opts = smartio_onepack_get_opts();
    $has_webp = function_exists('imagewebp') ? 'Yes' : 'No';
    $has_avif = function_exists('imageavif') ? 'Yes' : 'No';
    echo '<div class="wrap"><h1>Smart Image Optimizer — OnePack</h1>';
    echo '<p><strong>Encoder support</strong></p>';
    echo '<ul><li>WebP encoder: '.$has_webp.'</li><li>AVIF encoder: '.$has_avif.'</li></ul>';
    echo '<form method="post">';
    wp_nonce_field('smartio_onepack_save');
    echo '<h2>Safe Upload</h2>';
    echo '<p><label><input type="checkbox" name="prefer_gd" '.checked($opts['prefer_gd'],1,false).'/> Prefer GD over Imagick</label></p>';
    echo '<p><label><input type="checkbox" name="light_sizes" '.checked($opts['light_sizes'],1,false).'/> Lightweight sizes (thumbnail/medium/large only)</label></p>';
    echo '<p><label>Max dimension (big image threshold): <input type="number" name="big_image_max" value="'.esc_attr($opts['big_image_max']).'" min="1024" step="1"> px</label></p>';
    echo '<h2>Delivery</h2>';
    echo '<p><label><input type="checkbox" name="playground_force_webp" '.checked($opts['playground_force_webp'],1,false).'/> Playground mode: include WebP candidates without filesystem checks</label></p>';
    echo '<p><button class="button button-primary" name="smartio_onepack_save" value="1">Save Settings</button></p>';
    echo '</form>';
    echo '</div>';
}
