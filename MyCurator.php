<?php

/*
 * Plugin Name: MyCurator
 * Plugin URI: http://www.target-info.com
 * Description: Automatically curates articles from your feeds and alerts, using the Relevance engine to find only the articles you like
 * Version: 2.0.0
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
define ('MCT_AI_VERSION', '2.0.0');

//Globals for DB
global $wpdb, $ai_topic_tbl, $ai_postsread_tbl, $ai_sl_pages_tbl, $ai_logs_tbl;
$ai_topic_tbl = $wpdb->prefix.'topic';
$ai_postsread_tbl = $wpdb->prefix.'postsread';  
$ai_sl_pages_tbl = $wpdb->prefix.'sl_pages';
$ai_logs_tbl = $wpdb->prefix.'ai_logs';

//Activation hook
register_activation_hook(__FILE__, 'mct_ai_activehook');
//Get options
global $mct_ai_optarray;
$mct_ai_optarray = get_option('mct_ai_options');
if (empty($mct_ai_optarray)){
    $mct_ai_optarray = array (
        'ai_on' => TRUE,
        'ai_excerpt' => 50,
        'ai_log_days' => 7,
        'ai_short_linkpg' => "1",
        'ai_show_orig' => "1",
        'ai_keep_good_here' => "1",
        'ai_edit_makelive' => "1",
        'ai_save_thumb' => "1",
        'ai_now_date' => "1",
        'ai_num_posts' => 10,
        'ai_orig_text' => 'Click here to view original web page at',
        'ai_save_text' => 'Click here to view full article',
        'ai_embed_video' => "1",
        'ai_lookback_days' => 7,
        'ai_train_days' => 7,
        'MyC_version' => MCT_AI_VERSION
    );
    update_option('mct_ai_options',$mct_ai_optarray);
}
if (!isset($mct_ai_optarray['ai_excerpt'])) { 
    $mct_ai_optarray['ai_excerpt'] = 50;  //set up for existing installs
    update_option('mct_ai_options',$mct_ai_optarray);
}
if (empty($mct_ai_optarray['MyC_version']) || $mct_ai_optarray['MyC_version'] != MCT_AI_VERSION) {
    //Any code to run for this new version
    mct_ai_createdb();
    $mct_ai_optarray['MyC_version'] = MCT_AI_VERSION;
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
//For wp3.5 add filter to keep link manager enabled
add_filter( 'pre_option_link_manager_enabled', '__return_true' );

//Get support functions
include('MyCurator_posttypes.php');
include('MyCurator_local_classify.php');  
include('MyCurator_fcns.php');
include('MyCurator_notebk.php');
include_once('MyCurator_link_redir.php');

//Check for plan if we don't have one
if (empty($mct_ai_optarray['ai_plan']) && !empty($mct_ai_optarray['ai_cloud_token'])){  
    mct_ai_getplan();
}

function mct_ai_activehook() {
    //Set up basics on activation
    //
    //Set up default options
    global $mct_ai_optarray;
    
    //Create the data tables
    mct_ai_createdb();
    //Create the Training Page if not there already
    $details = array(
      'post_content'  => '[MyCurator_training_page]',
      'post_title'  =>  'MyCurator Training Page',
      'post_name' => sanitize_title('MyCurator Training Page'),
      'post_type' => 'page',
      'post_status' => 'private'
    );
    $trainpage = false;
    $pages = get_pages(array('post_status' => 'publish,private'));
    foreach ($pages as $page) {
        if (stripos($page->post_content,"MyCurator_training_page") !== false) {
            $trainpage = true;
        }
    }
    if (!$trainpage) wp_insert_post($details);
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
    
    if (mct_ai_menudisp()){
        add_menu_page('MyCurator', 'MyCurator','publish_posts',__FILE__,'mct_ai_firstpage');
        add_submenu_page(__FILE__,'Topics', 'Topics','manage_links',__FILE__.'_alltopics','mct_ai_mainpage');
        add_submenu_page(__FILE__,'New Topic','New Topic','manage_links',__FILE__.'_newtopic','mct_ai_topicpage');
        $notebk = add_submenu_page(__FILE__,'NoteBooks','NoteBooks','publish_posts',__FILE__.'_notebook','mct_nb_notebk_page');
        add_submenu_page(__FILE__,'Topic Sources Manager','Topic Source','manage_options',__FILE__.'_topicsource','mct_ai_topicsource');
        add_submenu_page(__FILE__,'Remove Topic','Remove Topic','manage_options',__FILE__.'_remove','mct_ai_removepage');
        $optionspage = add_submenu_page(__FILE__,'Options', 'Options','manage_links',__FILE__.'_options','mct_ai_optionpage');
        $getpage = add_submenu_page(__FILE__,'Get It & Source It', 'Get It & Source It','publish_posts',__FILE__.'_getit','mct_ai_getitpage');
        add_submenu_page(__FILE__,'Source Quick Add', 'Source Quick Add', 'manage_links','mct_ai_quick_source', 'mct_ai_quick_source'); //Quick Add
        add_submenu_page(__FILE__,'Create News Feed', 'News, Twitter, YouTube', 'manage_links', 'bwc_create_news', 'bwc_create_news');// Google News Feed
        add_submenu_page(__FILE__,'Logs','Logs','manage_links',__FILE__.'_Logs','mct_ai_logspage');
        add_submenu_page(__FILE__,'Report','Report','manage_links',__FILE__.'_Report','mct_ai_logreport');
        add_action('load-'.$getpage, 'mct_ai_queueit');
        add_action('load-'.$optionspage, 'mct_ai_queueit');
        add_action('load-'.$notebk,'mct_nb_queuejs');
    } else {
        $getpage = add_menu_page('Get It & Notebooks', 'Get It & Notebooks','publish_posts',__FILE__,'mct_ai_getitpage');
        $notebk = add_submenu_page(__FILE__,'NoteBooks','NoteBooks','publish_posts',__FILE__.'_notebook','mct_nb_notebk_page');
        add_action('load-'.$getpage, 'mct_ai_queueit');
        add_action('load-'.$notebk,'mct_nb_queuejs');
    }
}

function mct_ai_queueit(){
    //Queue needed scripts and styles
    wp_enqueue_script('jquery-ui-tabs');
    $style = plugins_url('css/MyCurator.css',__FILE__);
    wp_register_style('myctabs',$style,array(),'1.0.0');
    wp_enqueue_style('myctabs');
}

function mct_ai_firstpage() {
    //General Info page
    //Set up training page Link
    //Display other important links
    global $user_id, $wpdb, $mct_ai_optarray, $ai_topic_tbl;
    
    $token = $mct_ai_optarray['ai_cloud_token'];
    //Get training page link
    $page =  mct_ai_get_trainpage();
    if (!empty($page)) $trainpage = get_page_link($page->ID);
    //Any topics?
    $topics = $wpdb->get_var( "SELECT COUNT(*) FROM $ai_topic_tbl" );
    
    ?>
    <div class='wrap' >
        <div class="postbox-container" style="width:70%;">
            <?php screen_icon('plugins'); ?>
            <h2>Welcome to MyCurator</h2> 
            <p>This Home page has important links and information for using MyCurator.</p>
            <?php if (!current_user_can('manage_options')) { ?>
            <h3>Important Links</h3>
            <ul>
                    <li>- <a href="http://www.target-info.com/training-videos/" >Link to MyCurator Training Videos</a></li>
                    <li>- MyCurator <a href="http://www.target-info.com/documentation/" >Documentation</a></li>
                    <?php if (!empty($trainpage)) { ?>
                    <li>- <a href="<?php echo $trainpage; ?>" />Link to MyCurator Training Page on your site</a></li> <?php } ?>
            </ul>
            <h3>Continue Learning about MyCurator</h3>
            <ol>
                <li>View our <a href="http://www.target-info.com/training-videos/#curation" >Curation</a> training video to get some ideas.</li>
                <li>Review our <a href="http://www.target-info.com/training-videos/#notebook" >Notebooks</a> video and learn how to use this powerful tool.</li>
                <li>View our <a href="http://www.target-info.com/training-videos/#training" >Training</a> video and learn how to optimize MyCurator's classification ability.</li>
                <li>Review our <a href="http://www.target-info.com/training-videos/#getit" >Get It</a> video and learn how to use this bookmarklet to gather content.</li>
                <li>Check out the <a href="http://www.target-info.com/category/how-to/">How To</a> section on our Blog for tips and tricks.</li>
            </ol> 
            </div></div>
            <?php exit(); } //not an admin ?>
            <?php if (empty($token)) { ?>
            <h3>Getting Started</h3>
            <ol>
                <li>Check the requirements below to see if there are any problems</li>
                <li>View our <a href="http://www.target-info.com/training-videos/" >Quick Start</a> training video and begin curating in minutes!</li>
                <li>Get your API Key by following the Get API Key in the Important Links to the right.</li>
                <li>Go to the Options Menu Item and enter your MyCurator API Key.</li>
                <li>Enter some sources into Links using our Source It tool, Add a Topic and go!</li>
                <li>Review the video tutorials and documentation for more in-depth information (see documentation and training videos links to the right).</li>
            </ol>
            <h3>Requirements and Versions</h3>
            <?php mct_ai_vercheck(); ?>
            <?php } elseif (!$topics ) { //end No Token/training page, start no topic ?>
            
            <h3>Getting Started</h3>
            <ol>
                <li>View our <a href="http://www.target-info.com/training-videos/" >Quick Start</a> training video and begin curating in minutes.</li>
                <li>Plan your installation with our <a href="http://www.target-info.com/training-videos/#planning" >Planning</a> training video.</li>
                <li>Enter some sources using our Source It tool.</li>
                <li>Add a Topic and connect it with your Sources.</li>
                <li>When you have Sources and a Topic, MyCurator will start to gather articles to your Training Page - this may take up to a day to get started.</li>
                <li>Go to the Training Posts menu in the Dashboard to view your articles and begin curating to your live blog!</li>
                <li>Review the video tutorials and documentation for more in-depth information (see documentation and training videos links to the right).</li>
            </ol>

            <h3>MyCurator Plan Information</h3>
            <?php mct_ai_getplan(); echo mct_ai_showplan(); echo mct_nb_showlimits();?>
            <?php } else { //end no topic, start active ?>
            
            <h2>MyCurator Plan Information</h2>
            <?php mct_ai_getplan(); 
            $plan_display = mct_ai_showplan(true,false); 
            echo $plan_display;
            
            $trial = stripos($plan_display,'trial');
            if (stripos($plan_display,'ind') !== false) { ?>
                <h3>Expand your curation with more Topics and use MyCurator on your other web sites or blogs!  Just
                    <a href="http://www.target-info.com/myaccount/?token=<?php echo $token; ?>" >Upgrade to a Pro or Business Plan</a> for a low monthly fee and build your business and SEO.</h3>
            <?php }    //end Individual
            elseif ($trial) { ?>
            <h3>Make sure you <a href="http://www.target-info.com/myaccount/?token=<?php echo $token; ?>" >Purchase a Pro or Business Plan </a>
                before your Trial Period Ends!</h3>
            <?php    
            }  elseif (stripos($plan_display,'pro') !== false) { //end trial, must be pro      ?>
            <h3>You can add more sites and get Unlimited Topics with a <a href="http://www.target-info.com/myaccount/?token=<?php echo $token; ?>" >Business Plan Upgrade</a></h3>
            <?php } //end pro ?>
            <strong><?php echo mct_nb_showlimits(); ?></strong>
            <br><br><br>
            <h3>Continue Learning about MyCurator</h3>
            <ol>
                <li>View our <a href="http://www.target-info.com/training-videos/#curation" >Curation</a> training video to get some ideas.</li>
                <li>Review all of the <a href="http://www.target-info.com/documentation-2/documentation-options/" >Options</a> available in our Documentation.</li>
                <li>View our <a href="http://www.target-info.com/training-videos/#training" >Training</a> video and learn how to optimize MyCurator's classification ability.</li>
                <li>Review our <a href="http://www.target-info.com/training-videos/#notebook" >Notebooks</a> video and learn how to use this powerful tool.</li>
                <li>Tweak your topics and keywords using the <a href="http://www.target-info.com/documentation-2/documentation-logs/" >Logs</a> page.</li>
                <li>Check out the <a href="http://www.target-info.com/category/how-to/">How To</a> section on our Blog for tips and tricks.</li>
            </ol>   
            <?php } //end active messages ?>
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
                                                        <li>- MyCurator <a href="http://wordpress.org/support/plugin/mycurator" >support forum</a></li>
                                                        <?php if (empty($mct_ai_optarray['ai_cloud_token'])) { ?>
                                                        <li>- MyCurator API Key: <a href="http://www.target-info.com/pricing/" />Get API Key</a></li><?php } ?>
                                                        <li>- <a href="http://www.target-info.com/myaccount/?token=<?php echo $token; ?>" >My Account</a> at Target Info</li>
                                                        <?php if (!empty($trainpage)) { ?>
                                                        <li>- <a href="<?php echo $trainpage; ?>" />Link to MyCurator Training Page on your site</a></li> <?php } ?>
                                                </ul>
                                        </div>
                                </div>

                                <div id="breadcrumsnews" class="postbox">
                                        <div class="handlediv" title="Click to toggle"><br /></div>
                                        <h3 class="hndle"><span><?php echo "Latest news from Our Blog";?></span></h3>
                                        <div class="inside">
                                                <?php // <p style="font-weight: bold;">www.Target-info.com</p> ?>
                                                <?php mct_ai_showfeed( 'http://www.target-info.com/feed/', 5 );  ?>
                                                <?php //<p style="font-weight: bold;">Twitter @MyCurator</p> ?>
                                                <?php 
                                                $twit_append = '<li>&nbsp;</li>';
                                                $twit_append .= '<li><a href="http://twitter.com/mycurator/" >';
                                                $twit_append .= 'Follow @MyCurator on Twitter.</a></li>';
                                                $twit_append .= '<li><a href="http://www.target-info.com/feed/" >';
                                                $twit_append .= 'Subscribe to RSS news feed.</a></li>';
                                                //mct_ai_showfeed( 'http://api.twitter.com/1/statuses/user_timeline.rss?screen_name=mycurator', 5);
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
    $sql = "SELECT `topic_name`, `topic_status`, `topic_type`, `topic_cat`, `topic_tag`, `topic_sources`, `topic_options`
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
                <th>Assigned Author</th>
                <th>Sources</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($edit_vals as $row){
                $row = mct_ai_get_topic_options($row);
                echo('<tr>');
                echo('<td><a href="'.$editpage.trim($row['topic_name']).'" >'.$row['topic_name'].'</a></td>');
                echo('<td>'.$row['topic_type'].'</td>');
                echo('<td>'.mct_ai_status_display($row['topic_status'],'display').'</td>');
                echo('<td>'.get_cat_name($row['topic_cat']).'</td>');
                echo('<td>'.$row['opt_post_user'].'</td>');
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
    <?php mct_ai_getplan(); echo mct_ai_showplan(true,false); ?>
    </div>
<?php
}

function mct_ai_topicpage() {
    //This function creates the New/Edit topic page
    global $wpdb, $ai_topic_tbl, $mct_ai_optarray;

    //Initialize some variables
    $pagetitle = 'New Topic';
    $update_type = 'false';  //set up for insert
    $msg = '';
    $topic_name = '';
    $createcat = '';
    $error_flag = false;
    $edit_vals = array();
    $do_report = false;
    $no_more = false;
    
    //Set up user login dropdown
    $authusers = get_users(array('role' => 'author'));
    $editusers = get_users(array('role' => 'editor'));
    $moreusers = get_users(array('role' => 'administrator'));
    $notset = new stdClass;
    $notset->user_login = "Not Set";
    $allusers = array_merge(array($notset),$authusers,$editusers,$moreusers);

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
            'topic_status' => mct_ai_status_display(trim($_POST['topic_status']),'db'), 
            'topic_search_1' => trim(sanitize_text_field(stripslashes($_POST['topic_search_1']))),
            'topic_search_2' => trim(sanitize_text_field(stripslashes($_POST['topic_search_2']))),
            'topic_exclude' => trim(sanitize_text_field(stripslashes($_POST['topic_exclude']))),
            'topic_skip_domains' => $valid_str,
            'topic_cat' => strval(absint($_POST['topic_cat'])),
            'topic_tag' => strval(absint($_POST['topic_tag'])),
            'topic_tag_search2' => $_POST['topic_tag_search2'],
            'topic_sources' => $tsource,
            'opt_post_user' => $_POST['opt_post_user'],
            'opt_image_filter' => strval(absint($_POST['opt_image_filter'])),
            'topic_min_length' => strval(absint($_POST['topic_min_length']))
        );        
        // Get category create name
        $createcat = trim(sanitize_text_field($_POST['topic_createcat']));
        //Get the topic name and validate
        $topic_name = $edit_vals['topic_name'] = trim(sanitize_text_field($_POST['topic_name']));
        if ($topic_name == '') {
            $msg = 'Must have a Topic Name';
            $error_flag = true;
        } else {
            if (isset($mct_ai_optarray['ai_utf8']) && $mct_ai_optarray['ai_utf8']) {
                if (preg_match('{^[-\p{L}\p{N}\s]+$}u',$topic_name) != 1) $error_flag = true;
            } else {
                if (preg_match('{^[-a-zA-Z0-9\s]+$}',$topic_name) != 1) $error_flag = true;
            }
            if ($error_flag) $msg = "Topic Name may not contain special characters, just letters, - and numbers. ";
        }
        if (!$error_flag) {
            //Create Slug if needed
            if (empty($_POST['topic_slug'])){
                $topicslug = sanitize_title($topic_name);
            } else {
                $topicslug = $_POST['topic_slug'];
            }
            $edit_vals['topic_slug'] = $topicslug;
            //Save options into db field
            $edit_vals['topic_options'] = maybe_serialize(array(
                'opt_post_user' => $edit_vals['opt_post_user'],
                'opt_image_filter' => $edit_vals['opt_image_filter']));
            //unset opt fields not in db
            unset($edit_vals['opt_post_user']);
            unset($edit_vals['opt_image_filter']);            
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
                if (empty($msg)) $msg = "Topic $topic_name Added";
                else $msg = "Topic $topic_name Added - ".$msg;
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
                `topic_exclude`, `topic_skip_domains`, `topic_min_length`, `topic_cat`, `topic_tag`, `topic_tag_search2`, `topic_sources`, `topic_options`
                FROM $ai_topic_tbl
                WHERE topic_name = '$tname'";
        $edit_vals = $wpdb->get_row($sql, ARRAY_A);
        //Set status dropdown
        $curstat = mct_ai_status_display($edit_vals['topic_status'],'display');
        $typ = $edit_vals['topic_type'];
        $status_vals = array (
            mct_ai_status_display('Inactive','display'),
            mct_ai_status_display('Training','display'),
            mct_ai_status_display('Active','display')
        );

        //Set up cat/tag dropdown
        $cats['selected'] = $edit_vals['topic_cat'];
        $tags['selected'] = $edit_vals['topic_tag'];
        //Set up Relevance report
        if ($typ == 'Relevance'  && $curstat != 'Inactive' && current_user_can('manage_options')){
            $rel = new Relevance();
            $rpt = $rel->report($tname);
            if (!empty($rpt)) $do_report = true;
            unset($rel);
        }
        //Set up sources checkboxes
        $sources = array_map('trim',explode(',',$edit_vals['topic_sources']));
        //Set up options into edit vals
        $edit_vals = mct_ai_get_topic_options($edit_vals);
        
    } else {
        //New topic, if error, don't reset values
        $curstat = mct_ai_status_display('Training','display');
        if (empty($edit_vals)){
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
            $edit_vals['opt_post_user'] = 'Not Set';
            $edit_vals['opt_image_filter'] = '';
        } else {
            //error, so reset selected cat, tag, sources
            $cats['selected'] = $edit_vals['topic_cat'];
            $tags['selected'] = $edit_vals['topic_tag'];
            //Set up sources checkboxes
            $sources = array_map('trim',explode(',',$edit_vals['topic_sources']));            
        }
        $status_vals = array (
            mct_ai_status_display('Inactive','display'),
            mct_ai_status_display('Training','display')
        );
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
           <?php
           //If over plan limits, put up message and exit
            if ($update_type == 'false' ) {
                mct_ai_getplan();
                if (!mct_ai_showplan(false)) $no_more = true;
            }
       } 
    } else {
        //If over plan limits, put up message and exit
        if ($update_type == 'false' ) {
            mct_ai_getplan();
            if (!mct_ai_showplan(false)) $no_more = true;
        }
    }
    if ($no_more) {
         echo "<h3>You have reached the maximum number of Topics allowed for your plan and cannot add new Topics</h3>";
         echo mct_ai_showplan();
         exit();
    }
?>
       <p>Use spaces to separate keywords.  You can use phrases in Keywords by enclosing words in single or double quotes 
           (start and end quotes must be the same).  Use the root of a keyword and it will match all endings, for example manage 
           will match manages, manager and management. See <a href="http://www.target-info.com/training-videos/#topics" >Topics</a> video and 
           <a href="http://www.target-info.com/documentation-2/documentation-topics/" >Topics Documentation</a> for more details</p>
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
                <td><textarea name="topic_exclude" id="topic_exclude" rows="3" cols="100" ><?php echo esc_attr($edit_vals['topic_exclude']); ?></textarea>
                <span>&nbsp;<em>NONE of these terms may be in the article</em></span></td>    
            </tr>
            <tr>
                <th scope="row">Minimum Article Length (in words)</th>
                <td><input name="topic_min_length" type="input" id="topic_min_length" size="5" maxlength="5" value="<?php echo $edit_vals['topic_min_length']; ?>"  /></td>    
            </tr>
            <tr>
                <th scope="row">Exclude if No Image</th>
                <td><input name="opt_image_filter" type="checkbox" id="opt_image_filter" value="1" <?php checked('1', $edit_vals['opt_image_filter']); ?> /></td>    
            </tr> 
            <tr>
                <th scope="row">Skip These Domains</th>
                <td><textarea id='topic_skip_domains' rows='5' cols='100' name='topic_skip_domains'><?php echo $edit_vals['topic_skip_domains'] ?></textarea></td>    
            </tr>
            <tr>
                <th scope="row">Choose Type</th>
                <td><select name="topic_type" >
                    <option value="Filter" <?php selected($edit_vals['topic_type'],"Filter"); ?>>Filter</option>
                    <option value="Video" <?php selected($edit_vals['topic_type'],"Video"); ?>>Video</option>
                    <option value="Relevance" <?php selected($edit_vals['topic_type'],"Relevance"); ?>>Relevance</option></select></td>    
            </tr>
            <tr>
                   <th scope="row">Topic Status</th>
                   <td><select name="topic_status" >
                <?php foreach ($status_vals as $stat) {
                        echo('<option value="'.$stat.'" '.selected($curstat,$stat).'>'.$stat.'</option>' );
                      }
                ?>
                </select></td>
            </tr>
            <tr>
                <th scope="row">User for MyCurator Posts</th>
                <td><select name="opt_post_user" >
                <?php foreach ($allusers as $users){ ?>
                    <option value="<?php echo $users->user_login; ?>" <?php selected($edit_vals['opt_post_user'],$users->user_login); ?> ><?php echo $users->user_login; ?></option>
                <?php } //end foreach ?>
                    </select><span> (If Not Set will use user in Admin tab of Options)</span></td>       
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
            <?php if (current_user_can('manage_options')) { ?>
           <div class="submit">
          <input name="Submit" type="submit" value="Save Topic" class="button-primary" />
        </div>
            <?php } //end manage options check ?>
       </form> 
    </div>
<?php
}

function mct_ai_status_display($status,$ret){
    //Convert statuses into 'db' or 'display' values 
    if ($status == 'Inactive') return 'Inactive';  //Always the same
    if ($ret == 'db') {
        return ($status == 'Manual Curation - Training') ? 'Training' : 'Active';
    } else {
        return ($status == 'Training') ? 'Manual Curation - Training' : 'Auto Post Good - Active';
    }
}

function mct_ai_optionpage() {
    //Enter or edit MyCurator Options
    //Always check if db created here in case it didn't happen - especially multi-user
    //since they have to come here at least once to turn on the system
    global $mct_ai_optarray;
    
    $msg = '';
    $errmsg = '';
    //Set up user login dropdown
   $allusers = get_users(array('role' => 'editor'));
   $moreusers = get_users(array('role' => 'administrator'));
   $allusers = array_merge($moreusers,$allusers);
   
    if (isset($_POST['Submit']) ) {
        //create db just in case
         mct_ai_createdb();
        //load options into array and update db
        if (!current_user_can('manage_options')) wp_die("Insufficient Privelege");
        check_admin_referer('mct_ai_optionspg','optionset');
        $opt_update = array (
            'ai_log_days' => absint($_POST['ai_log_days']),
            'ai_on' => ($_POST['ai_on'] == FALSE ? FALSE : TRUE),
            'ai_cloud_token' => trim($_POST['ai_cloud_token']),
            'ai_train_days' => absint($_POST['ai_train_days']),
            'ai_lookback_days' => absint($_POST['ai_lookback_days']),
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
            'ai_num_posts' => absint($_POST['ai_num_posts']),
            'ai_post_title' => absint($_POST['ai_post_title']),
            'ai_new_tab' => absint($_POST['ai_new_tab']),
            'ai_no_quotes' => absint($_POST['ai_no_quotes']),
            'ai_now_date' => absint($_POST['ai_now_date']),
            'ai_post_img' => absint($_POST['ai_post_img']),
            'ai_img_align' => trim(sanitize_text_field($_POST['ai_img_align'])),
            'ai_img_size' => trim(sanitize_text_field($_POST['ai_img_size'])),
            'ai_no_anchor' => absint($_POST['ai_no_anchor']),
            'ai_no_inline_pg' => absint($_POST['ai_no_inline_pg']),
            'ai_no_train_live' => absint($_POST['ai_no_train_live']),
            'ai_no_fmthelp' => absint($_POST['ai_no_fmthelp']),
            'ai_show_full'  => absint($_POST['ai_show_full']),
            'ai_embed_video' => absint($_POST['ai_embed_video']),
            'ai_video_width' => absint($_POST['ai_video_width']),
            'ai_video_height' => absint($_POST['ai_video_height']),
            'ai_video_desc' => absint($_POST['ai_video_desc']),
            'ai_video_nolink' => absint($_POST['ai_video_nolink']),
            'ai_line_brk' => absint($_POST['ai_line_brk']),
            'ai_hide_menu' => absint($_POST['ai_hide_menu']),
            'ai_image_title' => absint($_POST['ai_image_title']),
            'ai_getit_tab' => absint($_POST['ai_getit_tab']),
            'ai_tw_conk' => trim(sanitize_text_field($_POST['ai_tw_conk'])),
            'ai_tw_cons' => trim(sanitize_text_field($_POST['ai_tw_cons'])),
            'ai_plan' => $mct_ai_optarray['ai_plan'],
            'MyC_version' => $mct_ai_optarray['MyC_version']
        );
        //Validation
        if (empty($opt_update['ai_log_days'])) $opt_update['ai_log_days'] = 7;
        if ($opt_update['ai_log_days'] > 90) $opt_update['ai_log_days'] = 90;
        if (empty($opt_update['ai_train_days'])) $opt_update['ai_train_days'] = 7;
        if ($opt_update['ai_train_days'] > 90) $opt_update['ai_train_days'] = 90;
        if (empty($opt_update['ai_lookback_days'])) $opt_update['ai_lookback_days'] = 7;
        if ($opt_update['ai_lookback_days'] > 90) $opt_update['ai_lookback_days'] = 90;
        
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
        //Set up twitter
        if (!empty($opt_update['ai_tw_conk']) && !empty($opt_update['ai_tw_cons'])) {
            require_once(plugin_dir_path(__FILE__).'lib/class-mct-tw-api.php');
            $credentials = array(
              'consumer_key' => $opt_update['ai_tw_conk'],
              'consumer_secret' => $opt_update['ai_tw_cons']
            );
            //Get bearer token with this call if needed
            add_filter( 'https_ssl_verify', '__return_false' );
            add_filter( 'https_local_ssl_verify', '__return_false' );
            $twitter_api = new mct_tw_Api( $credentials );
            if ($twitter_api->has_error) {
                $errmsg = 'Could Not Set Up Twitter Account: '.$twitter_api->api_errmsg;
            }
            remove_filter( 'https_ssl_verify', '__return_false' );
            remove_filter( 'https_local_ssl_verify', '__return_false' );
            unset ($twitter_api);
        } else {
            require_once(plugin_dir_path(__FILE__).'lib/class-mct-tw-api.php');
            //Reset bearer token if any credentials are empty
            $credentials = array(
              'consumer_key' => $opt_update['ai_tw_conk'],
              'consumer_secret' => $opt_update['ai_tw_cons']
            );
            $twitter_api = new mct_tw_Api( $credentials );
            unset ($twitter_api);
        }
    }
    //Get Options
    $cur_options = get_option('mct_ai_options');
    //Set values that may be empty after upgrade from older version
    if (empty($cur_options['ai_cron_period'])) $cur_options['ai_cron_period'] = '6';
    if (empty($cur_options['ai_num_posts'])) $cur_options['ai_num_posts'] = 10;
    if (empty($cur_options['ai_video_width'])) $cur_options['ai_video_width'] = 400;
    if (empty($cur_options['ai_video_height'])) $cur_options['ai_video_height'] = 300;
    if (empty($cur_options['ai_lookback_days'])) $cur_options['ai_lookback_days'] = 7;
    ?>
    <script>
    //<![CDATA[
    jQuery(function() {
        jQuery( ".mct-ai-tabs #tabs" ).tabs();
    });
    //]]>
    </script>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Options</h2> 
    <?php if (!empty($msg)){ ?>
       <div id="message" class="updated" ><p><strong><?php echo $msg ; ?></strong></p></div>
    <?php } ?>
       <?php if (!empty($errmsg)){ ?>
       <div id="message" class="error" ><p><strong><?php echo $errmsg ; ?></strong></p></div>
    <?php } ?>
    <p>Use this page to Turn On MyCurator and enter the Cloud Services Token.  
        You can set MyCurator options as described - 
        see <a href="http://www.target-info.com/documentation-2/documentation-options/" >Options Documentation</a> for more details.</p>
        <form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI'] . '&updated=true'); ?>" >
        <div class="mct-ai-tabs" >
         <div id="tabs">
            <ul>
            <li><a href="#tabs-1">Basic</a></li>
            <li><a href="#tabs-2">Curation</a></li>
            <li><a href="#tabs-3">Format</a></li>
            <li><a href="#tabs-4">Twitter</a></li>
            <li><a href="#tabs-5">Admin</a></li>
            </ul>
            <div id="tabs-1">            
                <table class="form-table" >
                    <tr><th><strong>Basic Settings</strong></th>
                <td> </td></tr>
                <tr>
                    <th scope="row">Turn on MyCurator?</th>
                    <td><input name="ai_on" type="checkbox" id="ai_on" value="1" <?php checked('1', $cur_options['ai_on']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Enter the API Key to Access Cloud Services</th>
                    <td><input name="ai_cloud_token" type="text" id="ai_cloud_token" size ="50" value="<?php echo $cur_options['ai_cloud_token']; ?>"  />
                    <?php if (empty($cur_options['ai_cloud_token'])) { ?><span>&nbsp;MyCurator API Key: <a href="http://www.target-info.com/pricing/" />Get API Key</a></span></td> <?php } ?>   
                </tr>            
                <tr>
                    <th scope="row">Save first article picture as featured post thumbnail?</th>
                    <td><input name="ai_save_thumb" type="checkbox" id="ai_save_thumb" value="1" <?php checked('1', $cur_options['ai_save_thumb']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Save First Image into Curated Post</th>
                    <td><input name="ai_post_img" type="checkbox" id="ai_post_img" value="1" <?php checked('1', $cur_options['ai_post_img']); ?>  />
                    <span>&nbsp;<em>If checked, you should turn off saving as Featured Image above or you may get duplicate images</em></span></td>    
                </tr>
                <tr>
                    <th scope="row">&raquo; Image Alignment</th>
                    <td><input name="ai_img_align" type="radio" value="left" <?php checked('left', $cur_options['ai_img_align']); ?>  /> Left
                        <input name="ai_img_align" type="radio" value="right" <?php checked('right', $cur_options['ai_img_align']); ?>  /> Right
                        <input name="ai_img_align" type="radio" value="center" <?php checked('center', $cur_options['ai_img_align']); ?>  /> Center
                        <input name="ai_img_align" type="radio" value="none" <?php checked('none', $cur_options['ai_img_align']); ?>  /> None
                    </td>    
                </tr> 
                <tr>
                    <th scope="row">&raquo; Image Size</th>
                    <td><input name="ai_img_size" type="radio" value="thumbnail" <?php checked('thumbnail', $cur_options['ai_img_size']); ?>  /> Thumbnail
                        <input name="ai_img_size" type="radio" value="medium" <?php checked('medium', $cur_options['ai_img_size']); ?>  /> Medium
                        <input name="ai_img_size" type="radio" value="large" <?php checked('large', $cur_options['ai_img_size']); ?>  /> Large
                        <input name="ai_img_size" type="radio" value="full" <?php checked('full', $cur_options['ai_img_size']); ?>  /> Full Size
                    </td>    
                </tr> 
                <tr>
                    <th scope="row">Use Post Title for Image Title & Alt Tag</th>
                    <td><input name="ai_image_title" type="checkbox" id="ai_image_title" value="1" <?php checked('1', $cur_options['ai_image_title']); ?>  /></td>    
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
                    <th scope="row">Enable Non-English Language Processing?</th>
                    <td><input name="ai_utf8" type="checkbox" id="ai_utf8" value="1" <?php checked('1', $cur_options['ai_utf8']); ?>  />
                    <span>&nbsp;<em>This must be checked if your blog is Not in English, see 
                            <a href="http://www.target-info.com/documentation-2/documentation-international/" >Documentation -  International</a></em></span></td> 
                </tr>
                <tr>
                    <th scope="row">Set Get It Default Tab to Notebooks </th>
                    <td>
                        <input name="ai_getit_tab" type="checkbox" id="ai_getit_tab" value="1" <?php checked('1', $cur_options['ai_getit_tab']); ?>  />
                    </td>    
                </tr> 
                </table>
            </div>
            <div id="tabs-2">
                <table class="form-table" >
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
                    <span>&nbsp;<em>Will create draft post and display in post editor on [Make Live] (except for Bulk Actions)</em></span></td>     
                </tr>  
                <tr>
                    <th scope="row">Show full Article Text in Single Post Page? </th>
                    <td><input name="ai_show_full" type="checkbox" id="ai_show_full" value="1" <?php checked('1', $cur_options['ai_show_full']); ?>  />
                    <span>&nbsp;<em>Not recommended, you may be open to copyright and other issues with original article owner - see <a href="http://www.target-info.com/documentation-2/documentation-options/">Options</a> documentation</em></span></td>    
                </tr>
                <tr>
                    <th scope="row">Do NOT show readable page in Training Popups </th>
                    <td><input name="ai_no_inline_pg" type="checkbox" id="ai_no_inline_pg" value="1" <?php checked('1', $cur_options['ai_no_inline_pg']); ?>  />
                    <span>&nbsp;<em>Check this if you have Formatting Problems on Training Page or Admin Training Posts</em></span></td>    
                </tr>
                <tr>
                    <th scope="row">Make Post Date 'Immediately' when Made Live</th>
                    <td><input name="ai_now_date" type="checkbox" id="ai_now_date" value="1" <?php checked('1', $cur_options['ai_now_date']); ?>  /></td>    
                </tr>
                <tr><th>Video -----------------</th><td><strong>The following options Only apply to Topics with a type of Video.</strong></td></tr>
                <tr>
                    <th scope="row">Embed Video in Post for Video Topic?</th>
                    <td><input name="ai_embed_video" type="checkbox" id="ai_embed_video" value="1" <?php checked('1', $cur_options['ai_embed_video']); ?>  /></td>     
                </tr> 
                <tr>
                    <th scope="row">&raquo;Size of Embed Iframe</th>
                    <td>Width <input name="ai_video_width" type="text" id="ai_video_width" size ="5" value="<?php echo $cur_options['ai_video_width']; ?>"  />&nbsp;
                        Height <input name="ai_video_height" type="text" id="ai_video_height" size ="5" value="<?php echo $cur_options['ai_video_height']; ?>"  /></td>    
                </tr>  
                <tr>
                    <th scope="row">Insert Description into Post for YouTube Videos?</th>
                    <td><input name="ai_video_desc" type="checkbox" id="ai_video_desc" value="1" <?php checked('1', $cur_options['ai_video_desc']); ?>  /></td>     
                </tr> 
                <tr>
                    <th scope="row">Do Not add link to embedded video.</th>
                    <td><input name="ai_video_nolink" type="checkbox" id="ai_video_nolink" value="1" <?php checked('1', $cur_options['ai_video_nolink']); ?>  /></td>     
                </tr> 
                </table>
            </div>
            <div id="tabs-3">
                <table class="form-table" >
                <tr><th><strong>Format Settings</strong></th>
                <td> </td></tr>
                <tr>
                    <th scope="row">Link to Original Page Text</th>
                    <td><input name="ai_orig_text" type="text" id="ai_orig_text" size ="50" value="<?php echo $cur_options['ai_orig_text']; ?>"  />
                        <span>&nbsp;<em>If using link to original web page, customize this text</em></span></td> 
                </tr>
                <tr>
                    <th scope="row">&raquo; Do Not Use this text as part of Link Anchor</th>
                    <td><input name="ai_no_anchor" type="checkbox" id="ai_no_anchor" value="1" <?php checked('1', $cur_options['ai_no_anchor']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Link to Saved Readable Page Text</th>
                    <td><input name="ai_save_text" type="text" id="ai_save_text" size ="50" value="<?php echo $cur_options['ai_save_text']; ?>"  />
                        <span>&nbsp;<em>If using link to saved readable page, customize this text</em></span></td> 
                </tr>
                <tr>
                    <th scope="row">Use Article Title Instead of Domain in Original Article Link</th>
                    <td><input name="ai_post_title" type="checkbox" id="ai_post_title" value="1" <?php checked('1', $cur_options['ai_post_title']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Open Original Article Link in New Tab</th>
                    <td><input name="ai_new_tab" type="checkbox" id="ai_new_tab" value="1" <?php checked('1', $cur_options['ai_new_tab']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Do Not Use Blockquotes on Excerpt</th>
                    <td><input name="ai_no_quotes" type="checkbox" id="ai_no_quotes" value="1" <?php checked('1', $cur_options['ai_no_quotes']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Do Not Show Training Tags on Live Site for Admins</th>
                    <td><input name="ai_no_train_live" type="checkbox" id="ai_no_train_live" value="1" <?php checked('1', $cur_options['ai_no_train_live']); ?>  /></td>    
                </tr>
                 <tr>
                    <th scope="row">Excerpt length in words:</th>
                    <td><input name="ai_excerpt" type="text" id="ai_excerpt" size ="5" value="<?php echo $cur_options['ai_excerpt']; ?>"  /></td>    
                </tr> 
                <tr>
                    <th scope="row">Save Line Breaks in Excerpt?</th>
                    <td><input name="ai_line_brk" type="checkbox" id="ai_line_brk" value="1" <?php checked('1', $cur_options['ai_line_brk']); ?>  />
                    <span>&nbsp;<em>Warning: Use Blockquoted excerpt with this option if you are displaying the full text readable article on the single post page - see <a href="http://www.target-info.com/documentation-2/documentation-options/">Options</a> documentation</em></span></td>    
                </tr>
                <tr>
                    <th scope="row"># of Articles shown on Training Page</th>
                    <td><input name="ai_num_posts" type="text" id="ai_num_posts" size ="5" value="<?php echo $cur_options['ai_num_posts']; ?>"  /></td>    
                </tr>  
                
                </table>
            </div>
            <div id="tabs-4">
                <h3>To Set up your Twitter Keys see below or our <a href=" http://www.target-info.com/documentation-2/documentation-twitter-api/" target="_blank">Documentation</a> page with screen shots</h3>
                <p>Go to the Developers website: <a href="https://dev.twitter.com/apps" target ="_blank">https://dev.twitter.com/apps</a>. Sign in with your Twitter Account.  
                    Click Create a new application button on the right.</p>
                <p>Fill in the Application Details page.  You should use your own application names and descriptions.  
                    Scroll to the bottom of the page, click the Yes I agree checkbox and enter the Captcha information.  
                    Click the Create your Twitter Application button at the bottom.</p>
                <p>Copy the Consumer Key and Consumer Secret from the details screen under the Oath Settings heading into 
                    the same fields below.  Click Save Options and you should be 
                    ready to process your Twitter searches and follows.</p>
                <h3>To Change your Twitter Keys</h3>
                <p>If you wish to switch to a new Twitter App, you need to first blank out one or both of the fields below and then Save Options.  
                    This will remove your old application from the database.  Come back to this Tab and then copy in your new Consumer Key and Secret then Save Options again.</p>
                <table class="form-table" >
                <tr><th><strong>Twitter App Settings</strong></th>
                <td> </td></tr>
                <tr>
                    <th scope="row">Twitter App Consumer Key</th>
                    <td><input name="ai_tw_conk" type="text" id="ai_tw_conk" size ="75" value="<?php echo $cur_options['ai_tw_conk']; ?>"  /></td> 
                </tr>
                <tr>
                    <th scope="row">Twitter App Consumer Secret</th>
                    <td><input name="ai_tw_cons" type="password" id="ai_tw_cons" size ="75" value="<?php echo $cur_options['ai_tw_cons']; ?>"  /></td> 
                </tr>
                </table>
            </div>    
            <div id="tabs-5">
                <table class="form-table" >
               <tr><th><strong>Administrative Settings</strong></th>
                <td> </td></tr>
                <tr>
                    <th scope="row">Do Not Save to Excerpt Field in Post</th>
                    <td><input name="ai_nosave_excerpt" type="checkbox" id="ai_nosave_excerpt" value="1" <?php checked('1', $cur_options['ai_nosave_excerpt']); ?>  />
                    <span>&nbsp;<em>Use this if your theme uses the_excerpt and you add comments to the post</em></span></td>     
                </tr> 
                <tr>
                    <th scope="row">User for MyCurator Posts</th>
                    <td><select name="ai_post_user" >
                    <?php foreach ($allusers as $users){ ?>
                        <option value="<?php echo $users->user_login; ?>" <?php selected($cur_options['ai_post_user'],$users->user_login); ?> ><?php echo $users->user_login; ?></option>
                    <?php } //end foreach ?>
                        </select></td>       
                </tr>                
                <tr>
                    <th scope="row">Shorten Links entry page?</th>
                    <td><input name="ai_short_linkpg" type="checkbox" id="ai_short_linkpg" value="1" <?php checked('1', $cur_options['ai_short_linkpg']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Remove Formatting Help</th>
                    <td><input name="ai_no_fmthelp" type="checkbox" id="ai_no_fmthelp" value="1" <?php checked('1', $cur_options['ai_no_fmthelp']); ?>  /></td>    
                </tr>
                <tr>
                    <th scope="row">Keep Log for How Many Days?</th>
                    <td><input name="ai_log_days" type="text" id="ai_log_days" size ="5" value="<?php echo $cur_options['ai_log_days']; ?>"  />
                    <em>Between 1 and 90 days</em></td>    
                </tr>
                <tr>
                    <th scope="row">Keep Training Posts for How Many Days?</th>
                    <td><input name="ai_train_days" type="text" id="ai_train_days" size ="5" value="<?php echo $cur_options['ai_train_days']; ?>"  />
                    <em>Between 1 and 90 days</em></td>    
                </tr>     
                 <tr>
                    <th scope="row">Look back How Many Days for Articles?</th>
                    <td><input name="ai_lookback_days" type="text" id="ai_lookback_days" size ="5" value="<?php echo $cur_options['ai_lookback_days']; ?>"  />
                    <em>Between 1 and 90 days</em></td>    
                </tr>     
                <tr>
                    <th scope="row">Hide MyCurator menu for non-Admins?</th>
                    <td><input name="ai_hide_menu" type="checkbox" id="ai_hide_menu" value="1" <?php checked('1', $cur_options['ai_hide_menu']); ?>  />
                    <em>Only for Paid Plans</em></td>    
                </tr>   
                </table>
            </div>
         </div>
        </div>
            <?php wp_nonce_field('mct_ai_optionspg','optionset'); ?>
            <?php if (current_user_can('manage_options')) { ?>
        <div class="submit">
          <input name="Submit" type="submit" value="Save Options" class="button-primary" />
        </div>
        <?php } //end manage options check ?>
        <em>Saves Options for All Tabs at once</em>
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
    See our <a href="http://www.target-info.com/training-videos/#sources" >Sources</a> video and <a href="http://www.target-info.com/documentation-2/documentation-sources/" >Sources Documentation</a> for more details</p>
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
                $wpdb->query($wpdb->prepare ("DELETE FROM $ai_topic_tbl WHERE topic_name = %s", $delname ));
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

function mct_ai_getitpage() {
    //Page to set up the get-it bookmarklet
    require_once('./admin.php');

    if (mct_ai_menudisp()) {
        $title = 'Get It & Source It Bookmarklets';
    } else { 
        $title = 'Get It Bookmarklet';
    }

    require_once('./admin-header.php');
    $source_it = get_js_code();
    $source_it = str_replace("_getit.","_sourceit.",$source_it);
    
    ?>
    <script>
    //<![CDATA[
    jQuery(function() {
        jQuery( ".mct-ai-tabs #tabs" ).tabs();
    });
    //]]>
    </script>
    <div class="wrap">
    <?php screen_icon('tools'); ?>
    <h2><?php echo esc_html( $title ); ?></h2>

    <?php if ( current_user_can('edit_posts') ) : ?>
    <div class="mct-ai-tabs" >
     <div id="tabs">
        <ul>
        <li><a href="#tabs-1">Get It</a></li>
        <?php if (current_user_can('edit_others_posts') && mct_ai_menudisp()) { ?>
        <li><a href="#tabs-2">Source It</a></li>
         <?php } //end current user can publish ?>
        </ul>
        <div id="tabs-1">
            <h2>Get It</h2>
                <p><?php _e('Get It is a bookmarklet: a little app that runs in your browser and lets you grab bits of the web. ');
                       if (mct_ai_menudisp()) _e(' See the <a href="http://www.target-info.com/training-videos/#getit" />Get It Training Video</a>.');
                   ?></p>
                <p><?php _e('Use Get It to save articles to your training posts or as draft posts as you are reading them in your browser or iPad!
                    Now you can add all of the content you find while browsing the web, twitter and your social networks.');?></p>
                <p><?php _e('After installing the Get It bookmarklet, whenever you see an article or blog post you would like to curate to your site,
                    just click the Get It bookmark, choose the Topic for this article and click Save to Training Page.  The article will be posted to your 
                    training page as "not sure", with an excerpt and the attribution links.  
                    If you choose Save as Draft the article will be saved as a regular post with a Draft Status.
                    Save as Draft and Edit will allow you to edit the Draft Post right now.  Just click the full screen icon (upper right) on your browser for more space.
                    The full readable page will be saved along with any images.'); ?></p>
                <p><?php _e('If Get It cannot grab the full text, it will post the article to the training posts or as a Draft post with the title and a link to the 
                    original web page.  You will not have the full text and images in the WordPress post editor.');?></p>
                <h3>PC/Mac Instructions</h3>
                <p class="description"><?php _e('Drag-and-drop the following link to your bookmarks bar') ?></p>
                <p class="pressthis"><a onclick="return false;" href="<?php echo htmlspecialchars( get_js_code() ); ?>"><span><?php _e('Get It') ?></span></a></p>
               
                <p class="description"><?php _e('If your bookmarks toolbar is hidden or your browser does not allow you to drag and drop the link then:') ?></p>
                <p class="description"><?php _e('Highlight the Bookmark code in the box below then Ctrl-c/Command-c to copy the code. Open your Bookmarks/Favorites manager and create a new bookmark/favorite. Edit the name to Get It and save.  
                    Click Manage/Organize Bookmarks/Favorites and edit the Get It entry you just created.  Paste the code into the URL/Location/Address field using Ctrl-v/Command-v.
                    Save the entry') ?></p>
                <p><textarea rows="6" cols="120" ><?php echo htmlspecialchars( get_js_code() ); ?></textarea></p>
                <h3>iPhone or iPad Instructions</h3>
                <p class="description"><?php _e('Touch the code box above once (keyboard appears) then touch and hold until the magnifier 
                    appears and choose Select All then Copy.  
                    Add a Bookmark and set the title to Get It then save.  Now touch the bookmarks option again and choose Edit bookmarks from the top right.
                    and select the Get It bookmark you just created.
                    Touch the location box then the x and remove the old location.  Now Touch and Paste your previous copy into the bookmark.  
                    Press the Bookmarks button at the top to finish editing and then touch done in the upper right.') ?></p>
                <h3>Android Phone/Tablet Instructions</h3>
                <p class="description"><?php _e('Touch the code box above until the Edit Text menu appears, 
                    choose Copy All.  Touch the menu and choose Add Bookmark.  Edit the title to Get It then touch the Location box 
                    until the Edit Text menu appears.  Choose Paste then Done to save the bookmark') ?></p>
            </div>
         <?php if (current_user_can('edit_others_posts') && mct_ai_menudisp()) { ?>
            <div id="tabs-2">
            <h2>Source It</h2>
                <p><?php _e('Source It is a bookmarklet: a little app that runs in your browser and lets you grab feeds directly from a site! 
                    See the <a href="http://www.target-info.com/training-videos/#sourceit" />Source It Training Video</a>');?></p>
                <p><?php _e('Use Source It to grab a feed and load it into your Links area when you are visiting a site that you want MyCurator
                    to read each day.  Source It can also easily grab a Google Alerts feed.');?></p>
                <p><?php _e('After installing the Source It bookmarklet, whenever you see a site whose feed you want to save to your Sources,
                    just click the Source It bookmark, change the title if you want, choose the Link Category for the feed or add a New Link Category, then click Save. 
                    The feed URL will be saved in your Links page, with the Link Category you designated.  MyCurator will now use this Source
                    for any Topics that use the designated Link Category of the feed'); ?></p>
                <p><?php _e('To save a Google Alert, go to your Google Alert account and click Manage Alerts.  You will see a list of all of your alerts.
                    Click on the feed symbol for the alert you wish to add.  Another tab will open up in your browser showing the raw feed information.
                    At this point click Source It.  Enter a name for this feed and choose a Link Category (or add a new Link Category).  Press Save 
                    and the alert will be stored in your Links.');?></p>
                <p><?php _e('If Source It cannot grab a feed from the site, you will see an error message.  You can try to manually find the feed and add it.');?></p>
                <h3>PC/Mac Instructions</h3>
                <p class="description"><?php _e('Drag-and-drop the following link to your bookmarks bar') ?></p>
                <p class="pressthis"><a onclick="return false;" href="<?php echo htmlspecialchars( $source_it ); ?>"><span><?php _e('Source It') ?></span></a></p>
               
                <p class="description"><?php _e('If your bookmarks toolbar is hidden or your browser does not allow you to drag and drop the link then:') ?></p>
                <p class="description"><?php _e('Highlight the Bookmark code in the box below then Ctrl-c/Command-c to copy the code. Open your Bookmarks/Favorites manager and create a new bookmark/favorite. Edit the name to Source It and save.  
                    Click Manage/Organize Bookmarks/Favorites and edit the Source It entry you just created.  Paste the code into the URL/Location/Address field using Ctrl-v/Command-v.
                    Save the entry') ?></p>
                <p><textarea rows="6" cols="120" ><?php echo htmlspecialchars( $source_it ); ?></textarea></p>
                <h3>iPhone or iPad Instructions</h3>
                <p class="description"><?php _e('Touch the code box above once (keyboard appears) then touch and hold until the magnifier 
                    appears and choose Select All then Copy.  
                    Add a Bookmark and set the title to Source it It then save.  Now touch the bookmarks option again and choose Edit bookmarks from the top right.
                    and select the Source It bookmark you just created.
                    Touch the location box then the x and remove the old location.  Now Touch and Paste your previous copy into the bookmark.  
                    Press the Bookmarks button at the top to finish editing and then touch done in the upper right.') ?></p>
                <h3>Android Phone/Tablet Instructions</h3>
                <p class="description"><?php _e('Touch the code box above until the Edit Text menu appears, 
                    choose Copy All.  Touch the menu and choose Add Bookmark.  Edit the title to Source It then touch the Location box 
                    until the Edit Text menu appears.  Choose Paste then Done to save the bookmark') ?></p>
            </div>
         <?php } //end current user can publish ?>
        </div>
    </div>
    </div>
    <?php
    endif;
}

function get_js_code(){
    $link = "javascript:
var d=document,
w=window,
e=w.getSelection,
k=d.getSelection,
x=d.selection,
s=(e?e():(k)?k():(x?x.createRange().text:0)),
f='" . plugins_url('MyCurator_getit.php',__FILE__) . "',
l=d.location,
e=encodeURIComponent,
u=f+'?u='+e(l.host+l.pathname+l.search)+'&t='+e(d.title)+'&s='+e(s)+'&v=4';
a=function(){if(!w.open(u,'t','toolbar=0,resizable=1,scrollbars=1,status=1,width=520,height=400'))l.href=u;};
if (/Firefox/.test(navigator.userAgent)) setTimeout(a, 0); else a();
void(0)";

    $link = str_replace(array("\r", "\n", "\t"),  '', $link);

    return $link;    
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
    if (isset($_POST['Reset-Log'])){
        mct_ai_clearlogs();
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
    //Get restart transient for display
    $restart = get_transient('mct_ai_last_feed');
    if ($restart && $type == MCT_AI_LOG_PROCESS) {
        $restart_arr = explode(':',$restart);
        $rtopic = $wpdb->get_var("select topic_name from $ai_topic_tbl where topic_id = '$restart_arr[0]'");
        $src_term = get_term($restart_arr[1],'link_category');
        $rsource = $src_term->name;
        $link_obj = get_bookmark($restart_arr[2]);
        $rfeed = $link_obj->link_name;
    }
    ?>
    <div class='wrap'>
    <?php screen_icon('plugins'); ?>
    <h2>MyCurator Logs</h2>    
     <p>MyCurator keeps logs of what it does with each article found in your feed sources.  
        See <a href="http://www.target-info.com/documentation-2/documentation-logs/" >Logs Documentation</a> for more details.</p>
     <p>You can reset the logs and the filter that keeps articles from being re-read.  If you do this, you will most likely
         get duplicate articles on your training page as previous articles are reprocessed.  Use this if you have made changes 
         to your Topics or Formatting options and wish to reset MyCurator to process all articles again.
         <form id="Reset" method="post" >
         <input name="Reset-Log" value="Reset Logs" type="submit" class="button-secondary" onclick="return confirm('Are you sure you want to Reset MyCurator Logs?  You may end up with a lot of duplicate articles on your training page!');" >
         </form></p>
    <?php
       if ($restart && $type == MCT_AI_LOG_PROCESS) {
           echo "<p>Restart with $rtopic - $rsource - $rfeed </p>";
       }
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
        $sql = "SELECT `logs_date`, `logs_topic`, `logs_type`, `logs_msg`, `logs_url`, `logs_source`
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
        <style>
            th.mct-log-date {width: 10%; }
            th.mct-log-topic {width: 20%; }
            th.mct-log-type {width: 10%; }
            th.mct-log-msg {width: 20%; }
            th.mct-log-src {width: 10%; }
            th.mct-log-url {width: 30%; }
        </style>
        <table class="widefat" >
            <thead>
                <tr>
                <th class="mct-log-date">Date</th>
                <th class="mct-log-topic">Topic</th>
                <th class="mct-log-type">Type</th>
                <th class="mct-log-msg">Message</th>
                <th class="mct-log-src">Source</th>
                <th class="mct-log-url">URL</th>
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
                echo('<td>'.$row['logs_source'].'</td>');
                if (!empty($row['logs_url'])){
                    echo('<td><a href="'.$row['logs_url'].'" >'.$row['logs_url'].'</a></td>');
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
    
    //Use WordPress defaults (from schema.php)
    $charset_collate = '';

    if ( ! empty($wpdb->charset) )
            $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
    if ( ! empty($wpdb->collate) )
            $charset_collate .= " COLLATE $wpdb->collate";
    
    //Topics table holds all of the topic data and the classifier db for this topic
    //NOTE: update MyCurator_getit.php default null topic with any changes here
    $sql = "CREATE TABLE $ai_topic_tbl (
            topic_id int(11) NOT NULL AUTO_INCREMENT,
            topic_name varchar(200) NOT NULL,
            topic_slug varchar(200) NOT NULL, 
            topic_status varchar(20) NOT NULL,
            topic_type varchar(20) NOT NULL,
            topic_search_1 text,
            topic_search_2 text,
            topic_exclude text,
            topic_sources longtext,
            topic_aidbfc longtext,
            topic_aidbcat longtext,
            topic_skip_domains longtext,
            topic_min_length int(11),
            topic_cat int(11),
            topic_tag int(11),
            topic_tag_search2 char(1),
            topic_options text,
            topic_last_run DATETIME,
            PRIMARY KEY  (topic_id),
            KEY topic_name (topic_name)
    ) $charset_collate;";
    
    require_once(ABSPATH.'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    //Posts read table keeps all of the posts read, including the readable page, from the rss feeds, for re-use by other topics and over time
    $sql = "CREATE TABLE $ai_postsread_tbl (
            pr_id int(11) NOT NULL AUTO_INCREMENT,
            pr_url varchar(1000) NOT NULL,
            pr_date DATETIME NOT NULL,
            pr_topics varchar(50),
            pr_page_content longtext,
            PRIMARY KEY  (pr_id),
            KEY pr_url (pr_url)
    ) $charset_collate;";
    dbDelta($sql);
    //Logs table keeps all of the logs for MyCurator
    $sql = "CREATE TABLE $ai_logs_tbl (
            logs_id int(11) NOT NULL AUTO_INCREMENT,
            logs_date DATETIME NOT NULL,
            logs_type varchar(20) NOT NULL,
            logs_topic varchar(200) NOT NULL,
            logs_url varchar(1000),
            logs_msg varchar(200) NOT NULL,
            logs_aiclass varchar(20),
            logs_aigood FLOAT(5,4),
            logs_aibad FLOAT(5,4),
            logs_source varchar(255),
            PRIMARY KEY  (logs_id)
    ) $charset_collate;";
    dbDelta($sql);
    //pages table keeps the readable page for each post
    $sql = "CREATE TABLE $ai_sl_pages_tbl (
            sl_page_id int(11) NOT NULL AUTO_INCREMENT,
            sl_page_content longtext NOT NULL,
            sl_post_id int(11),
            PRIMARY KEY  (sl_page_id),
            KEY sl_post_id (sl_post_id)
    ) $charset_collate;";
    dbDelta($sql);
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
    
    $response = wp_remote_get($url);
    if( is_wp_error( $response ) && stripos($response->get_error_message(),'timed out') === false ) {//ignore timeout - we expect it
        mct_ai_log('Blog',MCT_AI_LOG_PROCESS, 'Error '.$response->get_error_message()." starting MyCurator",$url);
    }
    
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

function mct_ai_showplan($display=true, $upgrade=true){
    //Show plan limits and current counts, if display is false, return whether topic can be used
    global $wpdb, $ai_topic_tbl, $mct_ai_optarray;
    
    //return false on no token, since we won't have plan
    if (empty($mct_ai_optarray['ai_cloud_token'])) return ($display) ? '<p><strong>Error - You need an API Key before you can add Topics</strong></p>' : false;
    //Get the plan
    if (empty($mct_ai_optarray['ai_plan'])){
        return ($display) ? "<p><strong>Error - No Plan Data Available, could not connect with cloud services.  Try again after 5 minutes. 
            If still having problems contact MyCurator support at support@target-info.com.</strong></p>" : false;
    }
    $plan = unserialize($mct_ai_optarray['ai_plan']);
    if ($plan['max'] == -1) {
        //error, invalid token or expired
        return ($display) ? "<p><strong>Error - ".$plan['name']." Try to correct the error and then try again after 5 minutes. 
            If still having problems contact MyCurator support at support@target-info.com.</strong></p>" : false;
    }
    if ($plan['max'] == 0){
        return ($display) ? '<p>Business Plan with Unlimited Topics</p>' : true;
    }
    //Get current topic counts
    $sql = "Select count(*) From $ai_topic_tbl";
    $cur_cnt = $wpdb->get_var($sql);
    if (!$display) {
        return ($cur_cnt >= $plan['max']) ? false : true;
    }
    //Get Token
    $token = $mct_ai_optarray['ai_cloud_token'];
    //Set up the display
    ob_start();
    ?>
    <h4><?php echo $plan['name']; ?> with <?php echo $plan['max']; ?> Topics maximum and <?php echo $cur_cnt; ?> currently used</h4>
    <?php 
    if ($upgrade && current_user_can('manage_options')) { 
        if (stripos($plan['name'],'ind') !== false) {
            echo '<p>If you would like to set up more topics than your current plan allows, or install MyCurator on more sites, <a href="http://www.target-info.com/myaccount/?token='.$token.'" >Upgrade to a Pro or Business Plan</a></p>';
        } else { //must be pro, business already returned
            echo '<p>If you would like to set up more topics than your current plan allows, or install MyCurator on more sites, <a href="http://www.target-info.com/myaccount/?token='.$token.'" >Go to My Account</a> on our site</p>';
        }
    }
    return ob_get_clean();
}

function mct_ai_menudisp(){
    global $mct_ai_optarray;
    
    $name = mct_ai_showplan(true, false);
    if (stripos($name,'error') !== false) return true;
    if (stripos($name,'individual plan') !== false) return true;
    
    if (!empty($mct_ai_optarray['ai_hide_menu']) && !current_user_can('manage_options')) return false;
    return true;
}

function mct_ai_clearlogs(){
    //Clears postsread and logs to reset mycurator
    global $ai_postsread_tbl, $mct_ai_optarray, $wpdb, $ai_logs_tbl;
    
    //clear out Postsread table
    $sql = "DELETE FROM $ai_postsread_tbl";
    $pr_row = $wpdb->query($sql);

    //clear out ai_log
    $sql = "DELETE FROM $ai_logs_tbl";
    $pr_row = $wpdb->query($sql);

}

function mct_ai_get_topic_options($edit_vals){
    //Break out options from a topic option field
    $allopts = maybe_unserialize($edit_vals['topic_options']);
    $edit_vals['opt_post_user'] = $allopts['opt_post_user'];
    $edit_vals['opt_image_filter'] = $allopts['opt_image_filter'];
    return $edit_vals;
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