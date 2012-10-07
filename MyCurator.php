<?php

/*
 * Plugin Name: MyCurator
 * Plugin URI: http://www.target-info.com
 * Description: Automatically curates articles from your feeds and alerts, using the Relevance engine to find only the articles you like
 * Version: 1.1.4
 * Author: Mark Tilly
 * Author URL: http://www.target-info.com
 * License: GPLv2 or later
/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/
/*
 * Filters Available:
 * apply_filter('mct_ai_postcontent',$post_content);
 *   This filter lets you modify the excerpted content of the MyCurator post, normally a blockquoted excerpt and the article link
 */

//Define some constants
define ('MCT_AI_REDIR','ailink');
define ('MCT_AI_LOG_ERROR','ERROR');
define ('MCT_AI_LOG_ACTIVITY','ACTIVITY');
define ('MCT_AI_LOG_PROCESS','PROCESS');

//Activation hook
register_activation_hook(__FILE__, 'mct_ai_activehook');
//Get options
$mct_ai_optarray = get_option('mct_ai_options');
if (empty($mct_ai_optarray)){
    $mct_ai_optarray = array (
        'ai_on' => TRUE,
        'ai_excerpt' => 50,
        'ai_log_days' => 7,
        'ai_train_days' => 7
    );
    update_option('mct_ai_options',$mct_ai_optarray);
}
if (empty($mct_ai_optarray['ai_excerpt'])) { 
    $mct_ai_optarray['ai_excerpt'] = 50;  //set up for existing installs
    update_option('mct_ai_options',$mct_ai_optarray);
}
//Set up menus
add_action('admin_menu', 'mct_ai_createmenu');
//Set up Cron
add_action ('mct_ai_cron_process', 'mct_ai_run_mycurator');
add_filter ('cron_schedules', 'mct_ai_set_cron_sched');

//Link manager, add rss column, change link entry form if requested
if ($mct_ai_optarray['ai_on']) {
    add_filter('manage_link-manager_columns','mct_ai_linkcol');
    add_action('manage_link_custom_column','mct_ai_linkcolout',10,2);
    if ($mct_ai_optarray['ai_short_linkpg']) add_action('add_meta_boxes_link','mct_ai_linkeditmeta');
}
//Globals for DB
$ai_topic_tbl = $wpdb->prefix.'topic';
$ai_postsread_tbl = $wpdb->prefix.'postsread';  
$ai_sl_pages_tbl = $wpdb->prefix.'sl_pages';
$ai_logs_tbl = $wpdb->prefix.'ai_logs';

//Get support functions
include('MyCurator_posttypes.php');
include('MyCurator_local_classify.php');  
include('MyCurator_fcns.php');
include_once('MyCurator_link_redir.php');

function mct_ai_activehook() {
    //Set up basics on activation
    //
    //Set up default options
    global $mct_ai_optarray;
    
    $opt_update = array (
        'ai_on' => TRUE,
        'ai_excerpt' => 50,
        'ai_log_days' => 7,
        'ai_train_days' => 7
    );

    if (empty($mct_ai_optarray)){
        add_option('mct_ai_options',$opt_update);
    }
    //Create the data tables
    mct_ai_createdb();
    
    //Redirect rules
    mct_sl_add_rule();
    flush_rewrite_rules();
    //register custom post type/taxonomy
    mct_ai_register();
    
}

function mct_ai_linkcol($colarray) {
    //Show a different set of link columns, including rss
    $colarray['rss'] = __( 'Feed URL' );
    unset($colarray['rel']);
    unset($colarray['visible']);
    unset($colarray['rating']);
    return $colarray;
}
function mct_ai_linkcolout($colname, $linkid){
    //Does the display of the rss column with link
    global $wp_object_cache;
    
    if ($colname == 'rss'){
        $thecache = $wp_object_cache->cache;
        $thismark = $thecache['bookmark'];
        $thislink = $thismark[$linkid];
        $short_url = url_shorten( $thislink->link_rss );
        echo '<a href="'.$thislink->link_rss.'">'.$short_url.'</a>';
    }
}

function mct_ai_linkeditmeta($linkpg){
    //This function removes unneeded meta boxes on the link edit page and adds in a new meta box for just the rss link
    //if requested in the options set up
    remove_meta_box('linktargetdiv', null, 'normal');
    remove_meta_box('linkxfndiv', null, 'normal');
    remove_meta_box('linkadvanceddiv', null, 'normal');
    add_meta_box('mctlinkrssfeed', __('Feed URL'), 'mct_ai_linkrssbox', null, 'normal', 'core');
}

function mct_ai_linkrssbox($link){
    //Adds the link rss meta box if we've shortened the links page
    ?>
    <td><input name="link_rss" class="code" type="text" id="rss_uri" value="<?php echo  ( isset( $link->link_rss ) ? esc_attr($link->link_rss) : ''); ?>" size="50" style="width: 95%" />
    <p><?php _e('Example: <code>http://feeds.feedburner.com/exampleblog</code> &#8212; don&#8217;t forget the <code>http://</code>'); ?></p>
<?php
}

function mct_ai_createmenu() {
    //Set up our Topics menu
    global $mct_ai_optarray;
    
    add_menu_page('MyCurator', 'MyCurator','manage_links',__FILE__,'mct_ai_firstpage');
    add_submenu_page(__FILE__,'Topics', 'Topics','manage_links',__FILE__.'_alltopics','mct_ai_mainpage');
    add_submenu_page(__FILE__,'New Topic','New Topic','manage_links',__FILE__.'_newtopic','mct_ai_topicpage');
    add_submenu_page(__FILE__,'Topic Sources Manager','Topic Source','manage_links',__FILE__.'_topicsource','mct_ai_topicsource');
    add_submenu_page(__FILE__,'Remove','Remove','manage_links',__FILE__.'_remove','mct_ai_removepage');
    add_submenu_page(__FILE__,'Options', 'Options','manage_links',__FILE__.'_options','mct_ai_optionpage');
    add_submenu_page(__FILE__,'Logs','Logs','manage_links',__FILE__.'_Logs','mct_ai_logspage');
    add_submenu_page(__FILE__,'Report','Report','manage_links',__FILE__.'_Report','mct_ai_logreport');
}

function mct_ai_firstpage() {
    //General Info page
    //Set up training page Link
    //Display other important links
    global $user_id, $mct_ai_optarray;
    
    $msg = '';
    $token = $mct_ai_optarray['ai_cloud_token'];
    $trainpage = '';
    if (isset($_POST['Submit']) && !empty($_POST['train_page'])) {
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_trainpage','trainpage');     
        //load options into array and update db
       $details = array(
          'post_content'  => '[MyCurator_training_page]',
          'post_author' => $user_id,
          'post_title'  =>  sanitize_text_field(trim($_POST['train_page'])),
          'post_name' => sanitize_title(trim($_POST['train_page'])),
          'post_type' => 'page',
          'post_status' => 'private'
        );
        wp_insert_post($details);
        flush_rewrite_rules(); //This happens only once and should make sure our rewrites are updated
        $msg = 'Training Page has been created';
    }
    //Get training page link
    $pages = get_pages(array('post_status' => 'publish,private'));
    foreach ($pages as $page) {
        if (stripos($page->post_content,"MyCurator_training_page") !== false) {
            $trainpage = get_page_link($page->ID);
            break;
        }
    }

    ?>
    <div class='wrap' >
        <div class="postbox-container" style="width:70%;">
            <?php screen_icon('plugins'); ?>
            <h2>Welcome to MyCurator</h2> 
            <?php if (!empty($msg)){ ?>
               <div id="message" class="updated" ><p><strong><?php echo $msg ; ?></strong></p></div>
            <?php } ?>
            <p>This page has important links and information for using MyCurator.</p>
            <h3>Getting Started</h3>
            <ol>
                <li>Check the requirements below to see if there are any problems</li>
                <li>Get your API Key by following the Get API Key in the Important Links to the right</li>
                <li>Go to the Options Menu Item and enter your MyCurator API Key</li>
                <li>Set up your training page where MyCurator will post articles for your review (see below)</li>
                <li>Review the video tutorials to learn how to set up MyCurator (see Training Videos link to the right)</li>
                <li>Enter some sources into Links, Add a Topic and go!</li>
                <li>Go to Your Account at Target Info to upgrade your MyCurator API Key and review your purchase history</li>
            </ol>
            <h3>Requirements and Versions</h3>
            <?php mct_ai_vercheck(); ?>
            <h3>MyCurator Training Page Information</h3>
            <?php if (empty($trainpage)) { ?>
                <p>You need to set up a Training Page where MyCurator will post articles while training the system and also articles it is not sure about
                 or it has classified as bad.  You can create a Page using the dashboard and enter the shortcode [MyCurator_training_page] as the
                 only text in the page contents.  Or you can have MyCurator create a Private training page by entering a name below and pressing Submit.</p>
                <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI'] ); ?>" >
                <table class="form-table" >
                    <tr>
                        <th scope="row">Enter a Name for the Training Page</th>
                        <td><input name="train_page" type="input" size="50"  /></td>    
                    </tr>
                </table>
                    <?php wp_nonce_field('mct_ai_trainpage','trainpage'); ?>
                <div class="submit">
                  <input name="Submit" type="submit" value="Submit" class="button-primary" />
                </div>
                </form>
            <?php } ?>
            <p></p>
            <p>Normally you would keep the training page out of your menus by making it a Private page so that only authorized users (Authors and above) may 
            train the MyCurator system.  When the Training page is created, you will be able to access it from the Training Page link on this page in the Important Links to the right (or set a bookmark in your 
            browser).  If you display the training page on your site, unauthorized users will see the articles but will be unable to train them.</p>
            <h3>MyCurator Volume Information</h3>
            <?php if ($token) { ?>
                <iframe src="http://www.target-info.com/tgtinfo_volume.php?token=<?php echo $token; ?>" width="100%" ></iframe>
                <h3>If your volume is over plan limits this month and last month, you should <a href="http://www.target-info.com/myaccount/" >Upgrade to a Pro or Business Plan</a></h3>
                &nbsp(You will need your Target Info account login credentials, or choose Lost your Password?)
            <?php } else { ?>
                <strong>After you get your API Key, refer back to this page periodically to review your MyCurator Article Processing Counts</strong>
            <?php } ?>
        </div>
        <div class="postbox-container" style="width:20%; margin-top: 35px; margin-left: 15px;">
                <div class="metabox-holder">	
                        <div class="meta-box-sortables">

                                <div id="breadcrumbslike" class="postbox">
                                        <div class="handlediv" title="Click to toggle"><br /></div>
                                        <h3 class="hndle"><span><?php echo "Important Links";?></span></h3>
                                        <div class="inside">
                                                <ul>
                                                        <li>- <a href="http://www.target-info.com/training-videos/" >Link to MyCurator Training Videos</a></li>
                                                        <li>- MyCurator <a href="http://www.target-info.com/documentation/" >Documentation</a></li>
                                                        <?php if (mct_ai_checkpage('support')) { ?>
                                                        <li>- MyCurator <a href="http://www.target-info.com/support/" >Support Forums at Target Info</a></li>
                                                        <?php } else {?>
                                                        <li>- MyCurator <a href="http://wordpress.org/support/plugin/mycurator" >support forum</a></li>
                                                        <?php } ?>
                                                        <?php if (empty($mct_ai_optarray['ai_cloud_token'])) { ?>
                                                        <li>- MyCurator API Key: <a href="http://www.target-info.com/api-key" />Get API Key</a></li><?php } ?>
                                                        <li>- <a href="http://www.target-info.com/myaccount/" >My Account</a> at Target Info</li>
                                                        <?php if (!empty($trainpage)) { ?>
                                                        <li>- <a href="<?php echo $trainpage; ?>" />Link to MyCurator Training Page on your site</a></li> <?php } ?>
                                                </ul>
                                        </div>
                                </div>

                                <div id="breadcrumsnews" class="postbox">
                                        <div class="handlediv" title="Click to toggle"><br /></div>
                                        <h3 class="hndle"><span><?php echo "Latest news from Target Info";?></span></h3>
                                        <div class="inside">
                                                <p style="font-weight: bold;">www.Target-info.com</p>
                                                <?php mct_ai_showfeed( 'http://www.target-info.com/feed/', 5 );  ?>
                                                <p style="font-weight: bold;">Twitter @tgtinfo</p>
                                                <?php 
                                                $twit_append = '<li>&nbsp;</li>';
                                                $twit_append .= '<li><a href="http://twitter.com/tgtinfo/" >';
                                                $twit_append .= 'Follow @tgtinfo on Twitter.</a></li>';
                                                $twit_append .= '<li><a href="http://www.target-info.com/feed/" >';
                                                $twit_append .= 'Subscribe to RSS news feed.</a></li>';
                                                mct_ai_showfeed( 'http://twitter.com/statuses/user_timeline/tgtinfo.rss', 5);
                                                echo "<ul>".$twit_append."</ul>";
                                                ?>
                                        </div>
                                </div>

                        </div>
                </div>
        </div>
    </div>
<?php
}

function mct_ai_checkpage($page){
    //Checks if page is up on target-info.com site
    $response = wp_remote_head("http://www.target-info.com/".$page, array('timeout' => 1));
    if (is_wp_error($response)) return false;
    if ($response['response']['code'] == 404) return false;
    return true;
}

function mct_ai_showfeed($url, $cnt){
    //echos the title for a feed at url, showing cnt entries
    $rss=fetch_feed($url);
    if (is_wp_error($rss)) return;  //nothing to show
    $rss_items = $rss->get_items(0,$cnt);
    echo "<ul>";
    foreach ($rss_items as $item){
        $title = $item->get_title();
        $link = $item->get_permalink();
        echo "<li><a href='$link' >$title</a></li>\n";
    }
    echo "</ul>";
}

function mct_ai_vercheck() {
    //Display version of install and whether requirements met
    
    //get image links
    $imggood = plugins_url('thumbs_up.png',__FILE__);
    $imgbad = plugins_url('thumbs_down.png',__FILE__);
    
    echo "<ul>";
    if (function_exists('curl_init')){
        echo "<li><img src='$imggood' ></img> - PHP Curl Installed";
    } else {
        echo "<li><img src='$imgbad' ></img> - PHP Curl NOT Installed - MyCurator will not work without it, contact your host provider!";
    }
    $version = floatval(phpversion());
    if ($version >= 5.2) {
        echo "<li><img src='$imggood' ></img> - PHP Version ".strval($version)." is OK";
    } else {
        echo "<li><img src='$imgbad' ></img> - PHP Version ".strval($version)." NOT 5.2 or Greater - MyCurator not tested with this version";        
    }
    $version = floatval(get_bloginfo('version'));
    if ($version >= 3.2) {
        echo "<li><img src='$imggood' ></img> - Wordpress Version ".strval($version)." is OK";
    } else {
        echo "<li><img src='$imgbad' ></img> - Wordpress Version ".strval($version)." NOT 3.2 or Greater - MyCurator not tested with this version";        
    }
    echo "</ul>";
}
function mct_ai_mainpage() {
    //Creates the All Topics list, with topic name and sources highlighted as links for editing
    global $wpdb, $ai_topic_tbl;
    
    // run ai process?
    if (isset($_POST['run_ai'])){
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_runai','runaiclick');     
        include_once ('MyCurator_local_proc.php');
        mct_ai_process_site();
        echo '<div class="wrap">';
        echo '<h2>MyCurator Process Completed</h2></div>';
        exit;
    }
    //Set up prefixes for links
    $ruri = $_SERVER['REQUEST_URI'];
    $pos = stripos($ruri,"_alltopics");
    $editpage = substr($ruri,0,$pos) .'_newtopic&edit=';
    $sourcepage = substr($ruri,0,$pos) . '_topicsource&edit=';
    //Get Values from Db
    $sql = "SELECT `topic_name`, `topic_status`, `topic_type`, `topic_cat`, `topic_tag`, `topic_sources`
            FROM $ai_topic_tbl";
    $edit_vals = $wpdb->get_results($sql, ARRAY_A);
    //render the page
    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Topics</h2>  
    <form name="manual_ai" method="post" >
        <p><input name="run_ai" type="hidden" value="run_ai" /></p>
        <?php wp_nonce_field('mct_ai_runai','runaiclick'); ?>
        <input name="run_ai_button" value="Run AI Process" type="submit" class="button-secondary">
        <br /><br />
    </form>
    <h4>Each of the MyCurator topics are listed below.  Click the Title to view or change any topic.  Click the Source field to add or update Sources.</h4>
        <table class="widefat" >
            <thead>
                <tr>
                <th>Topic</th>
                <th>Type</th>
                <th>Status</th>
                <th>Assigned Category</th>
                <th>Assigned Tag</th>
                <th>Sources</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($edit_vals as $row){
                echo('<tr>');
                echo('<td><a href="'.$editpage.trim($row['topic_name']).'" >'.$row['topic_name'].'</a></td>');
                echo('<td>'.$row['topic_type'].'</td>');
                echo('<td>'.$row['topic_status'].'</td>');
                echo('<td>'.get_cat_name($row['topic_cat']).'</td>');
                $tagterm = get_term($row['topic_tag'],'post_tag');
                if (!empty($tagterm)) echo('<td>'.$tagterm->name.'</td>');
                else echo '<td> </td>';
                if (empty($row['topic_sources'])){
                    $source_fld = "Add Sources";
                } else {
                    $source_fld = "Edit Sources";
                }
                echo('<td><a href="'.$sourcepage.trim($row['topic_name']).'" >'.$source_fld.'</a></td>');
                echo('</tr>');
            } ?>
           </tbody>
        </table>
    </div>
<?php
}

function mct_ai_topicpage() {
    //This function creates the New/Edit topic page
    global $wpdb, $ai_topic_tbl;

    //Initialize some variables
    $pagetitle = 'New Topic';
    $update_type = 'false';  //set up for insert
    $msg = '';
    $topic_name = '';
    $error_flag = false;
    $edit_vals = array();
    $do_report = false;

    //Set up cat/tag dropdown
    $cats = array (
        'orderby' => 'name',
        'hide_empty' => FALSE,
        'name' => 'topic_cat'
    );
    $tags = array (
        'orderby' => 'name',
        'name' => 'topic_tag',
        'hide_empty' => FALSE,
        'show_option_none' => 'No Tags',
        'taxonomy' => 'post_tag'
    );
    //Set up topic sources - get all link categories
    $taxname = 'link_category';
    $terms = get_terms($taxname);
    //Check if submit
    if (isset($_POST['Submit']) ) {
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_topicpg','topicpg'); 
        // Get the post values and sanitize
 
        //Clean up domains
        $valid_str = '';
        $varray = explode("\n",$_POST['topic_skip_domains']);
        foreach ($varray as $vstr){
            $vstr = trim(sanitize_text_field($vstr));
            if (strlen($vstr) != 0){
                $valid_str .= $vstr."\n";
            }
        }
        $valid_str = trim($valid_str);
        //Check for topic sources
        $tsource = '';
        if (!empty($_POST['sourceChk'])){
            $tsource = implode(',',$_POST['sourceChk']);
        }
        $edit_vals = array (
            'topic_type' => trim(sanitize_text_field($_POST['topic_type'])),
            'topic_status' => trim(sanitize_text_field($_POST['topic_status'])), 
            'topic_search_1' => trim(sanitize_text_field(stripslashes($_POST['topic_search_1']))),
            'topic_search_2' => trim(sanitize_text_field(stripslashes($_POST['topic_search_2']))),
            'topic_exclude' => trim(sanitize_text_field(stripslashes($_POST['topic_exclude']))),
            'topic_skip_domains' => $valid_str,
            'topic_cat' => strval(absint($_POST['topic_cat'])),
            'topic_tag' => strval(absint($_POST['topic_tag'])),
            'topic_tag_search2' => $_POST['topic_tag_search2'],
            'topic_sources' => $tsource,
            'topic_min_length' => strval(absint($_POST['topic_min_length']))
        );        
        // Get category create name
        $createcat = trim(sanitize_text_field($_POST['topic_createcat']));
        //Get the topic name and validate
        $topic_name = trim(sanitize_text_field($_POST['topic_name']));
        if ($topic_name == '') {
            $msg = 'Must have a Topic Name';
            $error_flag = true;
        } else {
            if (preg_match('{^[a-zA-Z0-9-_\s]+$}',$topic_name) != 1) $error_flag = true;
            if ($error_flag) $msg = "Topic Name may only contain characters, numbers, -, _ and spaces";
        }
        if (!$error_flag) {
            //Create Slug if needed
            if (empty($_POST['topic_slug'])){
                $topicslug = sanitize_title($topic_name);
            } else {
                $topicslug = $_POST['topic_slug'];
            }
            $edit_vals['topic_slug'] = $topicslug;
            
            if ($_GET['updated'] == 'true'){
                //Do an update
                $where = array('topic_name' => $topic_name);
                $wpdb->update($ai_topic_tbl, $edit_vals, $where);
                $msg = "Topic Updated";
            } else {
                //Create New Category if entered
                if (!empty($createcat)) {
                    $theterm = wp_insert_term($createcat,'category');
                    if (is_wp_error($theterm)){
                        $msg = $theterm->get_error_message();
                    } else {
                        $edit_vals['topic_cat'] = $theterm['term_id'];
                    }
                }
                //Do an insert
                $edit_vals['topic_name'] = $topic_name;
                $wpdb->insert($ai_topic_tbl, $edit_vals);
                if (empty($msg)) $msg = "Topic Added";
                else $msg = "Topic Added - ".$msg;
                //Add the new topic to the taxonomy database for the Target custom posts
                wp_insert_term($edit_vals['topic_name'],'topic');
                $edit_vals = '';
                $createcat = '';
            }
        }
    }
    if (isset($_GET['edit'])){
        //We came in on an edit call, so set up variables
        $tname = trim($_GET['edit']);
        $pagetitle = 'Edit Topic';
        $update_type = 'true'; //Means we have to do an update
        //Load values from db
        $sql = "SELECT `topic_name`, `topic_slug`, `topic_status`, `topic_type`, `topic_search_1`, `topic_search_2`, 
                `topic_exclude`, `topic_skip_domains`, `topic_min_length`, `topic_cat`, `topic_tag`, `topic_tag_search2`, `topic_sources`
                FROM $ai_topic_tbl
                WHERE topic_name = '$tname'";
        $edit_vals = $wpdb->get_row($sql, ARRAY_A);
        //Set status dropdown
        $stat = $edit_vals['topic_status'];
        $typ = $edit_vals['topic_type'];
        $status_vals = array ($stat);
        switch ($stat) {
            case 'Inactive': 
                $status_vals[] = 'Training';
                break;
            case 'Training':
                $status_vals[] = 'Inactive';
                $status_vals[] = 'Active';
                break;
            case 'Active':
                $status_vals[] = 'Inactive';
                $status_vals[] = 'Training';
                break;
        }
        //Set up cat/tag dropdown
        $cats['selected'] = $edit_vals['topic_cat'];
        $tags['selected'] = $edit_vals['topic_tag'];
        //Set up Relevance report
        if ($typ == 'Relevance'  && $stat != 'Inactive' && current_user_can('manage_options')){
            $rel = new Relevance();
            $rpt = $rel->report($tname);
            if (!empty($rpt)) $do_report = true;
            unset($rel);
        }
        //Set up sources checkboxes
        $sources = array_map('trim',explode(',',$edit_vals['topic_sources']));
    } else {
        //New topic, if error, don't reset values
        if (empty($edit_vals)){
            $edit_vals['topic_status'] = 'Training';  //Set defaults
            $edit_vals['topic_type'] = 'Relevance';
            $edit_vals['topic_tag_search2'] = '1';  //Default to use as tags
            $edit_vals['topic_name'] = '';
            $edit_vals['topic_slug'] = '';
            $edit_vals['topic_search_1'] = '';
            $edit_vals['topic_search_2'] = '';
            $edit_vals['topic_exclude'] = '';
            $edit_vals['topic_skip_domains'] = '';
            $edit_vals['topic_cat'] = '';
            $edit_vals['topic_tag'] = '';
            $edit_vals['topic_sources'] = '';
            $edit_vals['topic_min_length'] = '';
            $edit_vals['topic_createcat'] = '';
        } else {
            //error, so reset selected cat, tag, sources
            $cats['selected'] = $edit_vals['topic_cat'];
            $tags['selected'] = $edit_vals['topic_tag'];
            //Set up sources checkboxes
            $sources = array_map('trim',explode(',',$edit_vals['topic_sources']));            
        }
        $status_vals[] = 'Inactive';
        $status_vals[] = 'Training';
    }
    //Render page
    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator <?php echo $pagetitle; ?></h2>  
    <?php 
    if (!empty($msg)){ 
        if ($error_flag) { ?>
           <div id="message" class="error" ><p><strong><?php echo "TOPIC NOT CREATED: ".$msg ; ?></strong></p></div>
        <?php } else { ?>
           <div id="message" class="updated" ><p><strong><?php echo $msg ; ?></strong></p></div>
    <?php }
    }?>
       <p>Use spaces to separate keywords.  You can use phrases in Keywords by enclosing words in single or double quotes 
           (start and end quotes must be the same).  Use the root of a keyword and it will match all endings, for example manage 
           will match manages, manager and management. See <a href="http://www.target-info.com/documentation-2/documentation-topics/" >Topics Documentation</a> for more details</p>
       <p>Press Save Options button at bottom to save your entries/changes</p>
       <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI'] . '&updated='.$update_type); ?>"> 
        <table class="form-table" >
            <?php if($update_type == 'false') { ?>
            <tr>
                <th scope="row">Topic Name</th>
                <td><input name="topic_name" type="input" id="topic_name" size="100" maxlength="200" value="<?php echo $edit_vals['topic_name']; ?>"  /></td>    
            </tr>
            <?php } else { ?>
            <tr>
                <th scope="row">Topic Name</th>
                <td><?php echo $edit_vals['topic_name']; ?></td>  
                <input name="topic_name" type="hidden" id="topic_name" value="<?php echo $edit_vals['topic_name']; ?>"  />
            </tr>
            <?php } ?>            <tr>
                <th scope="row">Topic Search 1</th>
                <td><input name="topic_search_1" type="input" id="topic_search_1" size="100" value="<?php echo esc_attr($edit_vals['topic_search_1']); ?>"  />
                <span>&nbsp;<em>Each of these terms MUST be in the article</em></span></td>
            </tr>
            <tr>
                <th scope="row">Topic Search 2</th>
                <td><textarea name="topic_search_2" id="topic_search_2" rows="5" cols="100" ><?php echo esc_attr($edit_vals['topic_search_2']); ?></textarea>
                <span>&nbsp;<em>At Least 1 of these terms MUST be in the article</em></span></td>    
            </tr>
            <tr>
                <th scope="row">Topic Excluded</th>
                <td><input name="topic_exclude" type ="input" id="topic_exclude" size="100" value="<?php echo esc_attr($edit_vals['topic_exclude']); ?>"  />
                <span>&nbsp;<em>NONE of these terms may be in the article</em></span></td>    
            </tr><tr>
                <th scope="row">Minimum Article Length (in words)</th>
                <td><input name="topic_min_length" type="input" id="topic_min_length" size="5" maxlength="5" value="<?php echo $edit_vals['topic_min_length']; ?>"  /></td>    
            </tr>
            <tr>
                <th scope="row">Skip These Domains</th>
                <td><textarea id='topic_skip_domains' rows='5' cols='100' name='topic_skip_domains'><?php echo $edit_vals['topic_skip_domains'] ?></textarea></td>    
            </tr>
            <tr>
                <th scope="row">Choose Type</th>
                <td><select name="topic_type" >
                    <option value="Filter" <?php selected($edit_vals['topic_type'],"Filter"); ?>>Filter</option>
                    <option value="Relevance" <?php selected($edit_vals['topic_type'],"Relevance"); ?>>Relevance</option></select></td>    
            </tr>
            <tr>
                   <th scope="row">Topic Status</th>
                   <td><select name="topic_status" >
                <?php foreach ($status_vals as $stat) {
                        echo('<option value="'.$stat.'" '.selected($edit_vals['topic_status'],$stat).'>'.$stat.'</option>' );
                      }
                ?>
                </select></td>
            </tr>
            <tr>
                <th scope="row">Assign to Category</th>
                <td><?php wp_dropdown_categories($cats); ?><td>    
            </tr>
            <?php if ($update_type == 'false') { ?>
            <tr>
                <th scope="row">Or Create New Category</th>
                <td><input name="topic_createcat" type="input" id="topic_createcat" size="50" maxlength="200" value="<?php echo $createcat; ?>" /><span> (Will override any Category chosen above)</span></td>    
            </tr> 
            <?php } ?>
            <tr>
                <th scope="row">Use Search 2 Keywords as Tags</th>
                <td><input name="topic_tag_search2" type="checkbox" id="topic_tag_search2" value="1" <?php checked('1', $edit_vals['topic_tag_search2']); ?> /><span> (Will override Tag chosen below)</span></td>    
            </tr> 
            <tr>
                <th scope="row">OR Assign to a Single Tag</th>
                <td><?php wp_dropdown_categories($tags); ?><td>    
            </tr> 
        </table>
        <!-- Sources Selection -->
        <h3>Select Sources for this Topic</h3>
        <table class="form-table" >
        <?php foreach ($terms as $term) {  ?>
           <tr>
               <th scope="row"><?php echo $term->name; ?></th>
               <td><input name="sourceChk[]" type="checkbox" value="<?php echo $term->term_id; ?> "
                 <?php if (!empty($sources) && in_array($term->term_id,$sources)) echo 'checked="checked"'; ?>/></td>
           </tr>
        <?php } ?>
        </table>
        <!-- Show ai stats if admin -->
        <?php
        if ($do_report){  ?>
        <h3>MyCurator Relevance Statistics</h3>
        <table class="form-table" >
            <tr>
                <th scope="row">Relevance Good Items</th>
                <td><?php echo $rpt['good']; ?><td>  
            </tr>    
            <tr>
                <th scope="row">Relevance Bad Items</th>
                <td><?php echo $rpt['bad']; ?><td>  
            </tr>    
            <tr>
                <th scope="row">Relevance # of Words</th>
                <td><?php echo strval($rpt['dict']); ?><td>  
            </tr>  
            <tr>
                <th scope="row">Relevance DB Adjustment</th>
                <td><?php echo strval($rpt['shrinkdb']); ?><td>  
            </tr>    
            <tr>
                <th scope="row">Relevance Coefficient</th>
                <td><?php printf('%.4f',$rpt['coef']); ?><td>  
            </tr>    
        </table>
        <?php } ?>
        <?php wp_nonce_field('mct_ai_topicpg','topicpg'); ?>
            <!-- Topic Slug Hidden Fields -->
            <input name="topic_slug" type="hidden" id="topic_slug" value="<?php echo $edit_vals['topic_slug']; ?>" />
           <div class="submit">
          <input name="Submit" type="submit" value="Save Options" class="button-primary" />
        </div>
       </form> 
    </div>
<?php
}

function mct_ai_optionpage() {
    //Enter or edit MyCurator Options
    //Always check if db created here in case it didn't happen - especially multi-user
    //since they have to come here at least once to turn on the system

    mct_ai_createdb();
    $msg = '';
    //Set up user login dropdown
   $allusers = get_users(array('role' => 'editor'));
   $moreusers = get_users(array('role' => 'administrator'));
   $allusers = array_merge($allusers,$moreusers);
   
    if (isset($_POST['Submit']) ) {
        //load options into array and update db
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_optionspg','optionset');
        $opt_update = array (
            'ai_log_days' => absint($_POST['ai_log_days']),
            'ai_on' => ($_POST['ai_on'] == FALSE ? FALSE : TRUE),
            'ai_cloud_token' => trim($_POST['ai_cloud_token']),
            'ai_train_days' => absint($_POST['ai_train_days']),
            'ai_short_linkpg' => absint($_POST['ai_short_linkpg']),
            'ai_save_thumb' => absint($_POST['ai_save_thumb']),
            'ai_cron_period' => $_POST['ai_cron_period'],
            'ai_keep_good_here' => absint($_POST['ai_keep_good_here']),
            'ai_excerpt' => absint($_POST['ai_excerpt']),
            'ai_nosave_excerpt' => absint($_POST['ai_nosave_excerpt']),
            'ai_show_orig' => absint($_POST['ai_show_orig']),
            'ai_orig_text' => trim(sanitize_text_field($_POST['ai_orig_text'])),
            'ai_save_text' => trim(sanitize_text_field($_POST['ai_save_text'])),
            'ai_post_user' => trim(sanitize_text_field($_POST['ai_post_user'])),
            'ai_utf8' => absint($_POST['ai_utf8']),
            'ai_edit_makelive' => absint($_POST['ai_edit_makelive']),
        );
        update_option('mct_ai_options',$opt_update);
        $msg = 'Options have been updated';
        //Set up cron for auto processing
        if ($opt_update['ai_on']){
            if (wp_next_scheduled('mct_ai_cron_process')){
                wp_clear_scheduled_hook('mct_ai_cron_process');  //Clear out old entries
            }
            $cronperiod = 'mct6hour';  //default if not set
            if ($opt_update['ai_cron_period'] == '3') $cronperiod = 'mct3hour';
            if ($opt_update['ai_cron_period'] == '12') $cronperiod = 'twicedaily';
            if ($opt_update['ai_cron_period'] == '24') $cronperiod = 'daily';
            $hour = rand(4,8)-get_option('gmt_offset');
            $strt = mktime($hour);  
            wp_schedule_event($strt,$cronperiod,'mct_ai_cron_process');
        } else {
            if (wp_next_scheduled('mct_ai_cron_process')){
                wp_clear_scheduled_hook('mct_ai_cron_process');  //Clear out old entries
            }
        }
    }
    //Get Options
    $cur_options = get_option('mct_ai_options');
    if (empty($cur_options['ai_cron_period'])) $cur_options['ai_cron_period'] = '6';
    if (empty($cur_options['ai_orig_text'])) $cur_options['ai_orig_text'] = 'Click here to view original web page at';
    if (empty($cur_options['ai_save_text'])) $cur_options['ai_save_text'] = 'Click here to view full article';
    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Options</h2> 
    <?php if (!empty($msg)){ ?>
       <div id="message" class="updated" ><p><strong><?php echo $msg ; ?></strong></p></div>
    <?php } ?>
    <p>Use this page to Turn On MyCurator and enter the Cloud Services Token.  
        You can set MyCurator options as described - 
        see <a href="http://www.target-info.com/documentation-2/documentation-options/" >Options Documentation</a> for more details.</p>
        <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI'] . '&updated=true'); ?>" >
        <table class="form-table" >
            <tr>
                <th scope="row">Turn on MyCurator?</th>
                <td><input name="ai_on" type="checkbox" id="ai_on" value="1" <?php checked('1', $cur_options['ai_on']); ?>  /></td>    
            </tr>
            <tr>
                <th scope="row">Enter the API Key to Access Cloud Services</th>
                <td><input name="ai_cloud_token" type="text" id="ai_cloud_token" size ="50" value="<?php echo $cur_options['ai_cloud_token']; ?>"  />
                <?php if (empty($cur_options['ai_cloud_token'])) { ?><span>&nbsp;MyCurator API Key: <a href="http://www.target-info.com/api-key" />Get API Key</a></span></td> <?php } ?>   
            </tr>            
            <tr>
                <th scope="row">Keep Log for How Many Days?</th>
                <td><input name="ai_log_days" type="text" id="ai_log_days" size ="5" value="<?php echo $cur_options['ai_log_days']; ?>"  /></td>    
            </tr>
            <tr>
                <th scope="row">Keep Training Posts for How Many Days?</th>
                <td><input name="ai_train_days" type="text" id="ai_train_days" size ="5" value="<?php echo $cur_options['ai_train_days']; ?>"  /></td>    
            </tr>   
            <tr>
                <th scope="row">Save first article picture as featured post thumbnail?</th>
                <td><input name="ai_save_thumb" type="checkbox" id="ai_save_thumb" value="1" <?php checked('1', $cur_options['ai_save_thumb']); ?>  /></td>    
            </tr>
            <tr>
                <th scope="row">Shorten Links entry page?</th>
                <td><input name="ai_short_linkpg" type="checkbox" id="ai_short_linkpg" value="1" <?php checked('1', $cur_options['ai_short_linkpg']); ?>  /></td>    
            </tr>
            <tr>
                <th scope="row">Run MyCurator Every </th>
                <td><input name="ai_cron_period" type="radio" value="3" <?php checked('3', $cur_options['ai_cron_period']); ?>  /> 3
                    <input name="ai_cron_period" type="radio" value="6" <?php checked('6', $cur_options['ai_cron_period']); ?>  /> 6
                    <input name="ai_cron_period" type="radio" value="12" <?php checked('12', $cur_options['ai_cron_period']); ?>  /> 12
                    <input name="ai_cron_period" type="radio" value="24" <?php checked('24', $cur_options['ai_cron_period']); ?>  /> 24 Hours
                </td>    
            </tr> 
            <tr>
                <th scope="row">Excerpt length in words:</th>
                <td><input name="ai_excerpt" type="text" id="ai_excerpt" size ="5" value="<?php echo $cur_options['ai_excerpt']; ?>"  /></td>    
            </tr> 
            <tr>
                    <th scope="row">User for MyCurator Posts</th>
                    <td><select name="ai_post_user" >
                    <?php foreach ($allusers as $users){ ?>
                        <option value="<?php echo $users->user_login; ?>" <?php selected($cur_options['ai_post_user'],$users->user_login); ?> ><?php echo $users->user_login; ?></option>
                    <?php } //end foreach ?>
                        </select></td>       
                </tr>
            <tr><th><strong>Manual Curation Settings</strong></th>
            <td> </td></tr>
            <tr>
                <th scope="row">Keep good trainees on Training Page?</th>
                <td><input name="ai_keep_good_here" type="checkbox" id="ai_keep_good_here" value="1" <?php checked('1', $cur_options['ai_keep_good_here']); ?>  />
                <span>&nbsp;<em>Use [Make Live] to Post on blog.</em></span></td>    
            </tr>
            <tr>
                <th scope="row">Show original article link, not readable page?</th>
                <td><input name="ai_show_orig" type="checkbox" id="ai_show_orig" value="1" <?php checked('1', $cur_options['ai_show_orig']); ?>  /></td>    
            </tr>
            <tr>
                <th scope="row">Edit post when made live?</th>
                <td><input name="ai_edit_makelive" type="checkbox" id="ai_edit_makelive" value="1" <?php checked('1', $cur_options['ai_edit_makelive']); ?>  />
                <span>&nbsp;<em>Will create draft post and display in post editor on [Make Live]</em></span></td>     
            </tr>   
            <tr>
                <th scope="row">Do Not Save to Excerpt Field in Post</th>
                <td><input name="ai_nosave_excerpt" type="checkbox" id="ai_nosave_excerpt" value="1" <?php checked('1', $cur_options['ai_nosave_excerpt']); ?>  />
                <span>&nbsp;<em>Use this if your theme uses the_excerpt and you add comments to the post</em></span></td>     
            </tr> 
            <tr><th><strong>International Settings</strong></th>
            <td> </td></tr>
            <tr>
                <th scope="row">Link to Original Page Text</th>
                <td><input name="ai_orig_text" type="text" id="ai_orig_text" size ="50" value="<?php echo $cur_options['ai_orig_text']; ?>"  />
                    <span>&nbsp;<em>If using link to original web page, customize this text</em></span></td> 
            </tr>
            <tr>
                <th scope="row">Link to Saved Readable Page Text</th>
                <td><input name="ai_save_text" type="text" id="ai_save_text" size ="50" value="<?php echo $cur_options['ai_save_text']; ?>"  />
                    <span>&nbsp;<em>If using link to saved readable page, customize this text</em></span></td> 
            </tr>
            <tr>
                <th scope="row">Enable Non-English Language Processing?</th>
                <td><input name="ai_utf8" type="checkbox" id="ai_utf8" value="1" <?php checked('1', $cur_options['ai_utf8']); ?>  />
                <span>&nbsp;<em>This must be checked if your blog is Not in English, see 
                        <a href="http://www.target-info.com/documentation-2/documentation-international/" >Documentation -  International</a></em></span></td> 
            </tr>
        </table>
            
            <?php wp_nonce_field('mct_ai_optionspg','optionset'); ?>
        <div class="submit">
          <input name="Submit" type="submit" value="Save Options" class="button-primary" />
        </div>
        </form>
    </div>
<?php
}

function mct_ai_topicsource() {
    //Edit the topic sources
    
    global $wpdb, $ai_topic_tbl;
    $tname = '';
    $msg = '';
    $sources = array ();
    if (isset($_GET['edit'])){
        //Came in as edit, from the All Topics page, so we know the data to show
        $tname = trim(sanitize_text_field($_GET['edit']));
        $sql = "SELECT `topic_name`, `topic_sources`
            FROM $ai_topic_tbl
            WHERE topic_name = '$tname'";
        $edit_vals = $wpdb->get_row($sql, ARRAY_A);
        $sources = array_map('trim',explode(',',$edit_vals['topic_sources']));
    }
    if (isset($_POST['Submit']) ) {
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_setsource','sources');
        //load options into array and update db
        $tname = $_POST['topic_name'];
        $val_array = array('topic_sources' => implode(',',$_POST['sourceChk']));
        $where = array('topic_name' => $tname);
        $wpdb->update($ai_topic_tbl, $val_array, $where);
        $msg = "Sources have been updated";
    }
    if (isset($_POST['topic']) || isset($_POST['Submit'])){
        //Get Topic Values from Db
        if (isset($_POST['topic'])){
            $tname = trim(sanitize_text_field($_POST['topic_name']));
        }
        $sql = "SELECT `topic_name`, `topic_sources`
            FROM $ai_topic_tbl
            WHERE topic_name = '$tname'";
        $edit_vals = $wpdb->get_row($sql, ARRAY_A);
        $sources = array_map('trim',explode(',',$edit_vals['topic_sources']));
    }
    //Get all link categories
    $taxname = 'link_category';
    $terms = get_terms($taxname);
    //Get all topics for dropdown
    $sql = "SELECT `topic_name`
            FROM $ai_topic_tbl";
    $topic_vals = $wpdb->get_results($sql, ARRAY_A);
    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Topic Sources</h2> 
    <p>MyCurator relates feeds to the topics you set up based on Link Categories. Choose a Topic below and select one or more sources.  
    See <a href="http://www.target-info.com/documentation-2/documentation-sources/" >Sources Documentation</a> for more details</p>
    <?php if (!empty($msg)){ ?>
       <div id="message" class="updated" ><p><strong><?php echo $msg ; ?></strong></p></div>
    <?php } ?>
   <form name="select-topic" method='post'> 
	<input type="hidden" name="topic" value="select" />
        <select name="topic_name" >
          <?php foreach ($topic_vals as $topic){
              $topicnam = $topic['topic_name'];
               echo '<option value="'.$topicnam.'" '.selected($tname,$topicnam,false).'">'.$topicnam.'</option>';
          } ?>        
        </select>
	<input name="Select" value="Select Topic" type="submit" class="button-secondary" />
        <em>Choose Topic and Click Select Topic to Edit Sources for that Topic</em>
   </form>
<?php if (!empty($tname)){ ?>
   <form name="sources" method='post'> 
       <h4>Select Each Source Below</h4>
       <table class="form-table" >
      <?php foreach ($terms as $term) {  ?>
           <tr>
                <td><input name="sourceChk[]" type="checkbox" value="<?php echo $term->term_id; ?> "
                 <?php if (!empty($sources) && in_array($term->term_id,$sources)) echo 'checked="checked"'; ?>/>
                 <?php echo '&nbsp;<strong>'.$term->name.'</strong>'; ?></td>
                <td><?php echo $term->description; ?></td>
           </tr>
      <?php } ?>
       </table>
       <p>
           <?php wp_nonce_field('mct_ai_setsource','sources'); ?>
       <input type="hidden" name="topic_name" value="<?php echo $tname; ?>" />
       <input name="Submit" value="Submit" type="submit" class="button-primary"></p>
   </form>
    
<?php } ?>    
    </div>
<?php
}

function mct_ai_removepage() {
    global $wpdb, $ai_topic_tbl;
    //Need to remove topic, remove topics from taxonomy, any targets with this topic will eventually get cleared as we clean the target posts
    
    if (isset($_POST['Submit']) && isset($_POST['deleteChk']) ) {
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_deltop','removeit');
        //At least one chosen to delete, so do it
        $val_array = $_POST['deleteChk'];
        if (!empty($val_array)) {
            foreach ($val_array as $delname){
                //Delete Topic
                $termobj = get_term_by('name',$delname, 'topic');
                wp_delete_term($termobj->term_id, 'topic');
                $wpdb->query($wpdb->prepare ("DELETE FROM $ai_topic_tbl WHERE topic_name = '$delname'" ));
                $a = '';
            }
        }
    }
    //Get the topics
    $sql = "SELECT `topic_name`, `topic_type`, `topic_cat`, `topic_tag`
            FROM $ai_topic_tbl";
           // WHERE `topic_status` = 'Inactive'";
    $edit_vals = $wpdb->get_results($sql, ARRAY_A);

    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Remove Topics</h2>  
    <h4>Each of the MyCurator topics are listed below.  Click the checkbox next to any topic and it will be removed when you press Submit.</h4>
       <form name="select-topic" method='post'>
        <table class="widefat" >
            <thead>
                <tr>
                <th>Remove?</th>
                <th>Type</th>
                <th>Assigned Category</th>
                <th>Assigned Tag</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($edit_vals as $row){
                echo('<tr>');
                echo('<td><input name="deleteChk[]" type="checkbox" value="'.$row['topic_name'].'" />&nbsp;'.$row['topic_name'].'</td>');
                echo('<td>'.$row['topic_type'].'</td>');
                echo('<td>'.get_cat_name($row['topic_cat']).'</td>');
                $tagterm = get_term($row['topic_tag'],'post_tag');
                if (!empty($tagterm)) echo('<td>'.$tagterm->name.'</td>');
                echo('</tr>');
            } ?>
           </tbody>
        </table>
        <p>
            <?php wp_nonce_field('mct_ai_deltop','removeit'); ?>
        <input name="Submit" value="Submit" type="submit" class="button-primary"></p>
   </form>
    </div>
<?php
}

function mct_ai_logspage() {
    //Display the logs page, with dropdowns for filtering
    
    global $wpdb, $ai_logs_tbl, $ai_topic_tbl, $blog_id;
    
    $maxrow = 25;
    $alter = true;
    //Set current page from get
    $currentPage = 1;
    $topic = '';
    if (isset($_GET['paged'])){
        $currentPage = $_GET['paged'];
    }
    //Set up the filter variables
    if (isset($_REQUEST['topic'])){
        if ($_REQUEST['topic'] == 'ALL'){
            $topic = '';
        } else {
            $topic = urldecode($_REQUEST['topic']);
        }
    }
    if (isset($_REQUEST['type'])){
        if ($_REQUEST['type'] == 'ALL'){
            $type = '';
        } else {
            $type = $_REQUEST['type'];
        }
    } else {
        $type = MCT_AI_LOG_ACTIVITY;  //default type
    }
    if (isset($_POST['Filter'])){
        $currentPage = 1;  //reset paging when a filter selected
    }
    //Get total rows available
    $sql = "SELECT COUNT(*) as myCount FROM " .$ai_logs_tbl;
    if (!empty($topic) && !empty($type)){
        $sql .= " WHERE `logs_topic` = '$topic' AND `logs_type` = '$type'";
    } else {
        if (!empty($topic)){
            $sql .= " WHERE `logs_topic` = '$topic'";
        }
        if (!empty($type)){
            $sql .= " WHERE `logs_type` = '$type'";
        }
    }
    $counts = $wpdb->get_row($sql,ARRAY_A);
    $myCount = $counts['myCount'];
    
    //Get all topics for dropdown
    $sql = "SELECT `topic_name`
            FROM $ai_topic_tbl";
    $topic_vals = $wpdb->get_results($sql, ARRAY_A);
    if ($blog_id == 1) $topic_vals[] = array('topic_name' => 'Blog');

    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Logs</h2>    
     <p>MyCurator keeps logs of what it does with each article found in your feed sources.  
        See <a href="http://www.target-info.com/documentation-2/documentation-logs/" >Logs Documentation</a> for more details.</p>
    <?php
       print("<div class=\"tablenav\">"); 
       $qargs = array(
           'paged' => '%#%', 
           'topic' => urlencode($topic),
           'type' => $type);
       $page_links = paginate_links( array(
		'base' => add_query_arg($qargs ) ,
		'format' => '',
		'total' => ceil($myCount/$maxrow),
		'current' => $currentPage
	));
	//Pagination display
	if ( $page_links )
		echo "<div class='tablenav-pages'>$page_links</div>";
        //Select Topic
        echo '<div class = "alignleft" >';
        echo '<form id="Select" method="post" >';
        echo '<select name="topic">';
        echo '<option ';
        if (empty($topic)) echo 'SELECTED';
        echo ' value="ALL">View all Topics</option>';
        foreach ($topic_vals as $tops){
            $topicnam = $tops['topic_name'];
            echo '<option value="'.$topicnam.'" '.selected($topic,$topicnam,false).'>'.$topicnam.'</option>';
        }
        //Select log type
        echo '</select>';
        echo '<select name="type">';
        echo '<option value="'.MCT_AI_LOG_PROCESS.'" '.selected($type,MCT_AI_LOG_PROCESS,false).'>'.MCT_AI_LOG_PROCESS.'</option>';
        echo '<option value="'.MCT_AI_LOG_ERROR.'" '.selected($type,MCT_AI_LOG_ERROR,false).'>'.MCT_AI_LOG_ERROR.'</option>';
        echo '<option value="'.MCT_AI_LOG_ACTIVITY.'" '.selected($type,MCT_AI_LOG_ACTIVITY,false).'>'.MCT_AI_LOG_ACTIVITY.'</option>';
        echo '</select>';
        echo '<input name="Filter" value="Select Filter" type="submit" class="button-secondary">';
        echo '</form></div>';
        
        //Get Values from Db
        $bottom = ($currentPage - 1) * $maxrow;
	$top = $currentPage * $maxrow;
        $sql = "SELECT `logs_date`, `logs_topic`, `logs_type`, `logs_msg`, `logs_url`
            FROM $ai_logs_tbl ";
        if (!empty($topic) && !empty($type)){
            $sql .= " WHERE `logs_topic` = '$topic' AND `logs_type` = '$type'";
        } else {
            if (!empty($topic)){
                $sql .= " WHERE `logs_topic` = '$topic'";
            }
            if (!empty($type)){
                $sql .= " WHERE `logs_type` = '$type'";
            }
        }
        $sql .= " ORDER BY `logs_date` DESC LIMIT " . $bottom . "," . $maxrow;
        $edit_vals = array();
        $edit_vals = $wpdb->get_results($sql, ARRAY_A);
        ?>
        </div>
        <table class="widefat" >
            <thead>
                <tr>
                <th>Date</th>
                <th>Topic</th>
                <th>Type</th>
                <th>Message</th>
                <th>URL</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($edit_vals as $row){
                echo('<tr');
                if ($alter) {
		 	$alter = false;
		 	print(" class='alternate' ");
		} else {
			$alter = true;
		}
                echo ('>');
                echo('<td>'.$row['logs_date'].'</td>');
                echo('<td>'.$row['logs_topic'].'</td>');
                echo('<td>'.$row['logs_type'].'</td>');
                echo('<td>'.$row['logs_msg'].'</td>');
                if (!empty($row['logs_url'])){
                    echo('<td><a href="'.$row['logs_url'].'" >'.$row['logs_url'].'</a>');
                }
                echo('</tr>');
            } ?>
           </tbody>
        </table>

<?php
}

function mct_ai_logreport(){
    //Print out pie chart based on activity log
    //Use google pie chart api as an image
    global $wpdb, $mct_ai_optarray, $ai_logs_tbl;
  
    $sql = "SELECT `logs_msg`
            FROM $ai_logs_tbl WHERE `logs_type` = '".MCT_AI_LOG_ACTIVITY."' ";
    $logs = $wpdb->get_results($sql, ARRAY_A);
    $cnts = array();
    $tot = 0;
    foreach ($logs as $log){
        $tot += 1;
        if (stripos($log['logs_msg'],'Live') !== false) $cnts['Live'] += 1;
        if (stripos($log['logs_msg'],'short') !== false) $cnts['Too Short'] += 1;
        if (stripos($log['logs_msg'],'excluded') !== false) $cnts['Excluded'] += 1;
        if (stripos($log['logs_msg'],'search 1') !== false) $cnts['No Search 1'] += 1;
        if (stripos($log['logs_msg'],'search 2') !== false) $cnts['No Search 2'] += 1;
        if (stripos($log['logs_msg'],'Training') !== false) $cnts['Training'] += 1;
        if (stripos($log['logs_msg'],'good') !== false) $cnts['good'] += 1;
        if (stripos($log['logs_msg'],'bad') !== false) $cnts['bad'] += 1;
        if (stripos($log['logs_msg'],'not sure') !== false) $cnts['not sure'] += 1;
    }
    $name = "";
    $pcts = "";
    foreach ($cnts as $key => $val){
        $pctstr = strval(intval(($val/$tot)*100));
        $name .= $key." - ".$pctstr."|";
        $pcts .= $pctstr.",";
    }
    $name = substr($name,0,strlen($name)-1);
    $pcts = "t:".substr($pcts,0,strlen($pcts)-1);
    $outstr = '<img src="http://chart.apis.google.com/chart?cht=p3&chd='.$pcts.'&chs=700x300&chl='.$name.'" style="max-width: 100%;margin: 5px" />';
    
    echo     "<div class='wrap'>";
    echo "<h2>MyCurator Article Classification Report (numbers are %)</h2>";
    echo "<h3>Out of ".strval($tot)." Articles read over the last ".strval($mct_ai_optarray['ai_log_days'])." days";
    echo "<br /><br /><br />";
    echo $outstr;
    
}


function mct_ai_createdb(){
    //This function creates our tables, uses dbdelta for easier updating
    
    global $wpdb, $ai_topic_tbl, $ai_postsread_tbl, $ai_sl_pages_tbl, $ai_logs_tbl, $mct_ai_fdvals_tbl;
    //Topics table holds all of the topic data and the classifier db for this topic
    $sql = "CREATE TABLE `$ai_topic_tbl` (
            `topic_id` int(11) NOT NULL AUTO_INCREMENT,
            `topic_name` varchar(200) NOT NULL,
            `topic_slug` varchar(200) NOT NULL, 
            `topic_status` varchar(20) NOT NULL,
            `topic_type` varchar(20) NOT NULL,
            `topic_search_1` text,
            `topic_search_2` text,
            `topic_exclude` text,
            `topic_sources` longtext,
            `topic_aidbfc` longtext,
            `topic_aidbcat` longtext,
            `topic_skip_domains` longtext,
            `topic_min_length` int(11),
            `topic_cat` int(11),
            `topic_tag` int(11),
            `topic_tag_search2` char(1),
            PRIMARY KEY  (`topic_id`),
            KEY `topic_name` (`topic_name`)
    ) DEFAULT CHARSET=utf8 ;";
    
    require_once(ABSPATH.'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    //Posts read table keeps all of the posts read, including the readable page, from the rss feeds, for re-use by other topics and over time
    $sql = "CREATE TABLE `$ai_postsread_tbl` (
            `pr_id` int(11) NOT NULL AUTO_INCREMENT,
            `pr_url` varchar(1000) NOT NULL,
            `pr_date` DATETIME NOT NULL,
            `pr_topics` varchar(50),
            `pr_page_content` longtext,
            PRIMARY KEY  (`pr_id`),
            KEY `pr_url` (`pr_url`)
    ) DEFAULT CHARSET=utf8 ;";
    dbDelta($sql);
    //Logs table keeps all of the logs for MyCurator
    $sql = "CREATE TABLE `$ai_logs_tbl` (
            `logs_id` int(11) NOT NULL AUTO_INCREMENT,
            `logs_date` DATETIME NOT NULL,
            `logs_type` varchar(20) NOT NULL,
            `logs_topic` varchar(200) NOT NULL,
            `logs_url` varchar(1000),
            `logs_msg` varchar(200) NOT NULL,
            `logs_aiclass` varchar(20),
            `logs_aigood` FLOAT(5,4),
            `logs_aibad` FLOAT(5,4),
            PRIMARY KEY  (`logs_id`)
    ) DEFAULT CHARSET=utf8 ;";
    dbDelta($sql);
    //pages table keeps the readable page for each post
    $sql = "CREATE TABLE `$ai_sl_pages_tbl` (
            `sl_page_id` int(11) NOT NULL AUTO_INCREMENT,
            `sl_page_content` longtext NOT NULL,
            `sl_post_id` int(11),
            PRIMARY KEY  (`sl_page_id`)
    ) DEFAULT CHARSET=utf8 ;";
    dbDelta($sql);
}

function mct_ai_getsavedpage($sl_id){
    //this function returns the page content for a saved page given an id
    global $ai_sl_pages_tbl, $wpdb;
    
    $sql = "SELECT `sl_page_content`
            FROM $ai_sl_pages_tbl
            WHERE sl_page_id = '$sl_id'";
    $vals = $wpdb->get_row($sql, ARRAY_A);
    return $vals['sl_page_content'];
}

function mct_ai_getsavedpageid($postid){
    //this function returns an array of int saved page id's for the passed in post
    $sl_id = array();
    $newlinks = get_post_meta($postid,'mct_sl_newurl',true);
    if (!empty($newlinks)){
        foreach ($newlinks as $nlink){
            $pos = preg_match('{/'.MCT_AI_REDIR.'/(.*)$}',$nlink,$matches);  //stripos($newlinks[$i],'ailink/');
            if ($pos){
                $sl_id[] = intval(trim($matches[1]));
            }
        }
    }
    return $sl_id;
}

function mct_ai_run_mycurator(){
    //starts the mycurator processing when triggered by cron

     //use curl so we don't have to worry about local php implementations
    if (is_multisite()){
        global $blog_id;
        $url = plugins_url('MyCurator_process_page.php',__FILE__).'/?blogid='.strval($blog_id);
        if ($blog_id != 1) { //need an absolute url for curl
            $siteurl = get_site_url(1)."/";
            $pattern = "{(".$siteurl.")([^/]*)/(.*)$}";
            $url = preg_replace($pattern,"\\1\\3",$url); //remove blog path
        }
    } else {
        $url = plugins_url('MyCurator_process_page.php',__FILE__);
    }
    mct_ai_log('Blog',MCT_AI_LOG_PROCESS, 'Cron Starting MyCurator', $url);
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);  //Set timeout to 1ms so we return and let it run

    curl_exec($ch);
    curl_close($ch);
    exit();

}

function mct_ai_set_cron_sched($schedules){
    //Set up every 3 and 6 hour schedules for cron
    $schedules['mct3hour'] = array(
        'interval' => 10800,
        'display' => 'Every 3 Hours'
    );
    $schedules['mct6hour'] = array(
        'interval' => 21600,
        'display' => 'Every 6 Hours'
    );   
    return $schedules;
}

//These are the stopwords that will be ignored in classifying a document
$stopwords = array('a', 'about', 'above', 'above', 'across', 'after', 'afterwards', 'again', 'against', 'all', 'almost', 'alone',
 'along', 'already', 'also','although','always','am','among', 'amongst', 'amoungst', 'amount',  'an', 'and', 'another', 'any',
'anyhow','anyone','anything','anyway', 'anywhere', 'are', 'around', 'as',  'at', 'back','be','became', 'because','become',
'becomes', 'becoming', 'been', 'before', 'beforehand', 'behind', 'being', 'below', 'beside', 'besides', 'between', 'beyond', 
'bill', 'both', 'bottom','but', 'by', 'call', 'can', 'cannot', 'cant', 'co', 'con', 'could', 'couldnt', 'cry', 'de', 'describe',
 'detail', 'do', 'done', 'down', 'due', 'during', 'each', 'eg', 'eight', 'either', 'eleven','else', 'elsewhere', 'empty', 'enough'
, 'etc', 'even', 'ever', 'every', 'everyone', 'everything', 'everywhere', 'except', 'few', 'fifteen', 'fify', 'fill', 'find', 
'fire', 'first', 'five', 'for', 'former', 'formerly', 'forty', 'found', 'four', 'from', 'front', 'full', 'further', 'get', 
'give', 'go', 'had', 'has', 'hasnt', 'have', 'he', 'hence', 'her', 'here', 'hereafter', 'hereby', 'herein', 'hereupon', 'hers',
 'herself', 'him', 'himself', 'his', 'how', 'however', 'hundred', 'ie', 'if', 'in', 'inc', 'indeed', 'interest', 'into', 'is', 
'it', 'its', 'itself', 'keep', 'last', 'latter', 'latterly', 'least', 'less', 'ltd', 'made', 'many', 'may', 'me', 'meanwhile', 
'might', 'mill', 'mine', 'more', 'moreover', 'most', 'mostly', 'move', 'much', 'must', 'my', 'myself', 'name', 'namely', 'neither', 
 'never', 'nevertheless', 'next', 'nine', 'no', 'nobody', 'none', 'noone', 'nor', 'not', 'nothing', 'now', 'nowhere', 'of', 'off', 
 'often', 'on', 'once', 'one', 'only', 'onto', 'or', 'other', 'others', 'otherwise', 'our', 'ours', 'ourselves', 'out', 'over', 'own',
 'part', 'per', 'perhaps', 'please', 'put', 'rather', 're', 'same', 'see', 'seem', 'seemed', 'seeming', 'seems', 'serious', 'several',
 'she', 'should', 'show', 'side', 'since', 'sincere', 'six', 'sixty', 'so', 'some', 'somehow', 'someone', 'something', 'sometime', 
 'sometimes', 'somewhere', 'still', 'such', 'system', 'take', 'ten', 'than', 'that', 'the', 'their', 'them', 'themselves', 'then', 
 'thence', 'there', 'thereafter', 'thereby', 'therefore', 'therein', 'thereupon', 'these', 'they', 'thickv', 'thin', 'third', 'this',
 'those', 'though', 'three', 'through', 'throughout', 'thru', 'thus', 'to', 'together', 'too', 'top', 'toward', 'towards', 'twelve', 
  'twenty', 'two', 'un', 'under', 'until', 'up', 'upon', 'us', 'very', 'via', 'was', 'we', 'well',
 'were', 'what', 'whatever', 'when', 'whence', 'whenever', 'where', 'whereafter', 'whereas', 'whereby', 'wherein', 'whereupon', 
'wherever', 'whether', 'which', 'while', 'whither', 'who', 'whoever', 'whole', 'whom', 'whose', 'why', 'will', 'with', 'within',
 'without', 'would', 'yet', 'you', 'your', 'yours', 'yourself', 'yourselves', 'the');

//We ignore 3 letter words, but we can add specific ones back here
$threeletter = array('new');
?>