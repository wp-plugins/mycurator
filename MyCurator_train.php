<?php
/* This is the server page that handles training requests from blog pages
 *
*/
/** WordPress  Bootstrap */
include_once (dirname(dirname(dirname(dirname(__FILE__)))) .  DIRECTORY_SEPARATOR."wp-load.php");

$sendback = wp_get_referer();  //How to get back
$sendback = remove_query_arg( array('good', 'bad', 'move', 'multi'), $sendback );
//Handle training a post
if (isset($_GET['bad']) || isset($_GET['good'])){
    //call train_post with post id, topic, category
    if (!current_user_can('edit_published_posts')){
        wp_die('Insufficient Priveleges to Train Post');
    }
    if (isset($_GET['bad'])){
        $cat = 'bad';
        $pid = intval($_GET['bad']);
    }
    if (isset($_GET['good'])){
        $cat = 'good';
        $pid = intval($_GET['good']);
    }
    check_admin_referer('mct_ai_train_'.$cat.$pid);
    // Get the topic name
    $terms = get_the_terms( $pid, 'topic' );
    $tname = '';
    if (count($terms) == 1 ) { //should only be one
        //The array key is the id
        $tids = array_keys($terms);
        $term = $terms[$tids[0]];
        $tname = $term->name;
    }
    if ($tname != ''){
        mct_ai_trainpost($pid, $tname, $cat);
        if ($cat == 'bad'){
            wp_trash_post($pid);
            $sendback = add_query_arg('ids',$pid, $sendback);
            wp_redirect($sendback);
        }
        if (isset($_GET['move'])){
            mct_ai_movepost($pid);  //same post as we just trained
        }
    }
    
} else {
    
    if (isset($_GET['move'])){
        if (!current_user_can('edit_published_posts')){
            wp_die('Insufficient Priveleges to Move Post');
        }
        $pid = intval($_GET['move']);
        check_admin_referer('mct_ai_move'.$pid);
        mct_ai_movepost($pid);
    }
    if (isset($_GET['multi'])){
        if (!current_user_can('edit_published_posts')){
            wp_die('Insufficient Priveleges to Move Post');
        }
        $pid = intval($_GET['multi']);
        check_admin_referer('mct_ai_multi'.$pid);
        wp_set_object_terms($pid,'multi','ai_class',false);
    }
}
$sendback = add_query_arg('ids',$pid, $sendback);
wp_redirect($sendback);

function mct_ai_movepost($thepost){
    global $wpdb, $ai_topic_tbl, $mct_ai_optarray;
    
    //Move a post - change post type from target_ai to post
    //read topic table for this category/tag
    $topic = wp_get_object_terms($thepost,'topic',array('fields' => 'names'));
    if (!empty($topic)) {
        $tname = $topic[0];  //should always only be one
        $sql = "SELECT topic_cat, topic_tag, topic_tag_search2 FROM $ai_topic_tbl WHERE topic_name = '$tname'";
        $row = $wpdb->get_row($sql);
        if (!empty($row)){
            $details = array();
            $details['ID'] = $thepost;
            $details['post_category'] = array($row->topic_cat);
            if ($row->topic_tag_search2){
                $details['tags_input'] = get_post_meta($thepost,'mct_ai_tag_search2', true);
            } else {
                $tagterm = get_term($row->topic_tag,'post_tag');
                $details['tags_input'] = array($tagterm->name);
            }
            $details['post_type'] = 'post';
            if ($mct_ai_optarray['ai_edit_makelive']) {
                $details['post_status'] = 'draft';
                if ($mct_ai_optarray['ai_now_date']) {
                    $details['edit_date'] = true;
                    $details['post_date'] = '';
                    $details['post_date_gmt'] = "0000-00-00 00:00:00";
                }
            } elseif ($mct_ai_optarray['ai_now_date']) {
                $details['edit_date'] = true;
                $details['post_date'] = '';
            }
            wp_update_post($details);
            if ($mct_ai_optarray['ai_edit_makelive']){
                $edit_url = get_edit_post_link( $thepost, array('edit' => '&amp;'));
                wp_redirect($edit_url);
                exit();
            }
        }
    }
}
