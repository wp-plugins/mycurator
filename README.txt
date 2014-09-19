=== MyCurator ===
Contributors: mtilly
Donate link: 
Tags: content curation, content curation tools, content curation software, content curation plugin, content marketing, article writing, content writing, blog article
Requires at least: 3.3
Tested up to: 4.0
Stable tag: 2.1.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

MyCurator Content Curation Software Makes it Easy to Build Quality Content for Your WordPress Site.

== Description ==

MyCurator works tirelessly in the background delivering a pipeline of interesting, usable content to you throughout the day.  
Quickly curate this content onto your site with great curation tools.  Our Get It Bookmarklet lets you curate content as you browse the web.
Use our Notebooks to save the best articles and posts you find for use in your original articles or building complex content curation posts.

MyCurator is powerful corporate level content curation software brought to you as a plugin.  It uses a cloud process to perform 
intensive AI processing and article classification.  This allows you to train MyCurator to find only the best, most relevant articles 
for your site, weeding out the bulk of off-topic, spammy, content on the web. 

Find out more at <a href="http://www.target-info.com/" >MyCurator</a>.

* Pro-actively finds the content you want, no need to search every time, inspiring you to regularly curate new posts.
* The full text, attribution link and all images for each article are available in the WordPress post editor, just click on a paragraph, list, table or image and its inserted into your blog article immediately.
* Notebooks let you research ideas and save related articles, both from MyCurator's automated discovery and your web explorations.
* Notebooks are like Evernote, only integrated into your WordPress Editor.
* Curate content to your custom post types
* Customize content curation to your specific niche by choosing what sources to read and what to look for in each article
* For even more content curation accuracy, simply train with thumbs up/down and weed out off-topic and spam articles
* Get It Bookmarklet allows you to discover and curate content while browsing and add it to your training page for future curation.
* Special Video content curation features to find and embed videos directly into curated posts.
* Easily handle 100's of articles a day and multiple writers, categories and sites with Quick post and bulk content curation tools.
* MyCurator curates content in any language, and all posts are in the native language of your site.
* Works with any RSS source of articles including Google alerts, blogs, news feeds and Twitter searches (follows embedded links).
* Source It Bookmarklet to easily capture RSS feeds.
* Selective Auto-Post capability uses AI process to deliver highly targeted, quality articles to your community.

MyCurator allows you to curate content for a Topic (equivalent to WordPress Categories) for free, but you will have to obtain an API Key to access the 
cloud services.  For those who need to curate a lot of different topics or for multiple sites, low priced monthly plans are 
available - always with a free 30 day trial.  Visit http://www.target-info.com/pricing/ for more information on how MyCurator can add 
content curation to your content marketing capabilities.

== Installation ==

Using the WordPress Plugin Installer, Choose Add New and then Upload, choose the zip file on your computer that 
you downloaded from the WordPress repository.  After Uploading, choose Install and then activate

After activation choose the new MyCurator menu item and follow the Getting Started instructions.  

In the Important Links section, click on the Get API Key link to get your API Key.  Paste your API Key into 
the API Key field on the MyCurator Options menu item.


== Frequently Asked Questions ==

= Does MyCurator work with most themes? =

MyCurator creates a simple excerpted blog post, with a link to the original page.  It should work with most
themes. Optionally, it will try to save an image from the article as the featured image for the post.

= Does MyCurator work with Network Sites? =

Yes, MyCurator works with Network sites.  You must have a key for each site.  Enterprise options are available 
for businesses and larger sites that need many keys.

= Does MyCurator work with other languages? =

Yes, MyCurator will read and post articles written in other languages.  All characters in the UTF-8 encoding, the same
used by WordPress, will be displayed.  You can customize the link to the original page to match your language.  
The admin pages, documentation and the training videos are only in English at this time.

= How often does MyCurator read my sources for articles? =

You can set MyCurator to process every 3, 6, 12 or 24 hours, depending on how often new articles are posted to 
your sources.  Processing happens in the background, with most of the processing off-site using our
cloud services.

== Screenshots ==

1. Classification Report of how MyCurator has processed your articles.

2. Topic page tells MyCurator what to look for in your sources.

3. Saved page meta box with images in WordPress post editor.

== Changelog ==

= 2.1.4 =
* Option added to remove duplicate articles within a Topic using their title 
* Ability to add a start and end date to a Topic for selective background processing
* Streamlined startup of Get It and Source It to use wp_load
* Remove old featured image when adding a new featured image using the click-copy feature
* Check for no Topics on Get It to remove php warning

= 2.1.3 =
* Update to format Image Pop-up in Editor for WordPress 3.9

= 2.1.2 =
* Option to put link to original article above excerpt.
* Fix to loss of training tags - automatically add topic taxonomy if missing
* Fix to replace Cron jobs if they are somehow missing when in Training Posts page
* Set default Look Back Days to 30

= 2.1.1 =
* Curate articles to custom post types
* Better image capture for more curated posts with images.
* More video options to align video in the post, capture the thumbnail and shorten description to excerpt length.
* Option to place embedded image at bottom of post.
* Manually run selected Topics using the Run AI Process button in Topics
* Fix formatting error in Get It and Source It with WP 3.8

= 2.1.0 =
* New Request Processing Mode provides faster background processing of articles.
* New Inline background process is more reliable especially on highly secured hosts.
* Create new Notebooks on the fly in Get It and [NoteBk] training tag.
* Fix set up of new blog when MyCurator is network activated in Multisite network.

= 2.0.0 =
* Notebooks let you save any articles found by MyCurator for future use in curated posts or writing articles.
* Create a YouTube search directly from MyCurator
* Save YouTube video description along with embedded video for Video Topic
* Get It has a new tab to save articles to your Notebooks
* Support for changes to Google Alerts RSS delivery
* Index page for saved page meta box in editor if more than 5 articles
* Excluded keyword entry is now a text box
* Background processing updated to restart with last feed if ended early
* Training Posts All and Trash counts updated as you work with items

= 1.3.4 =
* Support for new Twitter API V1.1 allowing Twitter searches and follows to be active again.
* Source It will capture Talkwalker alerts from the RSS page view.
* Line breaks are formatted and saved correctly in the Quick post popup, and copying article text will keep breaks.
* Get It uses ID of user who captures the article, not default for the Topic.
* Fix to readable page to handle $nnn format problems.
* Changes to the background process of MyCurator to stop multiple simultaneous runs by WP-Cron.

= 1.3.3 =
* Click to Copy any paragraph, table or list into your post from the Saved Page meta box.
* Click to insert an image or set as featured from any image in your Saved Page meta box.
* Get It will save article as a Draft post, and you can follow into the Editor.
* Save line breaks to the excerpt created by MyCurator.
* Use Post Title as the Image title and for the Image Alt tag.
* Options for Insert image are on Basic Options tab with Featured Option.
* Display the source for each article in the Logs.
* Use the post link rather then the image url when you click on an inserted image.

= 1.3.2 =
* Add the ability to enter an author for each Topic, with role of Author or higher.
* Add the ability to filter by author on the Training Posts admin page.
* If a user has an Author role, they can only train and post training articles where they are the author.
* MyCurator menu items and ability to save changes now depend on role, Editor can manage Sources, Author can only use Get It.
* You can now exclude any article if it has no image using a new checkbox on the Topic page.
* If the featured or embedded image found by MyCurator has an alt text, that is used for the title in the media library and the alt text for the image.
* For video Topic, suppress showing related videos at end if a youtube video is embedded.

= 1.3.1 =
* Add support for twitter account following (such as @tgtinfo) in Twitter Search
* Support finding article link in twitter stream not embedded as html
* Ability to reset logs and re-read articles after changing Topics or formatting
* New option to change the initial look back period for articles up to 90 days
* Fix to validate numeric Admin options between 1 and 90 days
* Move Source Quick Add and News or Twitter menu items to MyCurator menu from Links menu
* Option to display full text readable page on single post page even with Original Article link
* Remove format problems information from Training Posts trash page

= 1.3.0 =
* Upgrade to Ajax technology on training page for much faster processing
* New Quick tag allows entry of comments/notes and immediate publishing or save to draft
* Full article text popup and in Quick tag
* Training Posts page in the WordPress admin now has full training tags for each article in a list format
* Bulk curation options in Training Posts admin page, including the ability to change the author

= 1.2.2 =
* Video curation topic with the ability to embed videos in your posts automatically
* Insert image into the post directly, with options for alignment and size
* New formatting options for the attribution link and whether to use block quotes
* Publish immediately option rather than the date the article was found
* Remove more duplicates when using twitter search feeds
* Get It and Source It close automatically

= 1.2.1 =
* Multi article curation has been enabled with a new [Multi] training tag.  Bring several articles at once into your WordPress editor for round-up, weekly highlighs and other complex curations.
* New Source It Bookmarklet tool to add feeds to your Links Sources with just a click. 
* New Sources Quick Add to use instead of Add New Links for faster manual source entry.
* Stay in position on training page after using training tags
* Expanded Topic status to highlight Manual Curation vs Auto Post status
* Initial options are set for manual curation when first installed.

= 1.2.0 =
* New Get It Bookmarklet lets you save content as you browse the web on your computer, tablet or phone directly to your training page.  
* Get It Bookmarklet <a href="http://www.target-info.com/training-videos/" />Training Video</a> and <a href="http://www.target-info.com/documentation-2/documentation-get-it/" >Documentation</a>
* Ready for WordPress 3.5 - You must upgrade to version 1.2.0 or later for MyCurator to work with WordPress 3.5 as they are making changes to the Links pages.
* Support for new pricing by Topic, see http://www.target-info.com/pricing/ and the MyCurator Topic pricing widget near the bottom of that page.

= 1.1.4 =
* Fixed problems with non-English characters in the Topic Keywords not matching articles.
* Added option to filter and train non-English articles using UTF-8 character processing to handle extended character sets
* Added links to documentation on the Administrative pages
* Fixed a problem with Filter type topics not having Trash and [Make Live] tags

= 1.1.3 =
* Added new option to choose the Admin or Editor user that will be used in MyCurator posts
* Added MyCurator article processing statistics to MyCurator initial menu page
* Added link to MyCurator documentation on MyCurator initial menu page

= 1.1.2 =
* Fix to delete saved featured post thumbnail image when a training post is deleted
* If you saved first picture as a featured post thumbnail, you will find many Unattached images in your Media Library
* Remove unattached images by choosing Unattached link at top of Media Library page, then use Bulk Actions to Delete Permanently
* See http://www.target-info.com/2012/09/01/update-to-version-1-1-2-to-fix-image-delete-problem/ for more information

= 1.1.1 =
* To support languages, you can customize the text of the link to the original or saved page
* Fix to allow special language characters in article excerpt
* Allow deletion of any Topic
* New option to Not store excerpt field on post
* More validation on Topic Name
* Trim spaces from API Key entry

= 1.1.0 =
* Display article full text in WordPress post editor meta box
* Display all images found in-line in text as well as an images section at bottom of meta box
* Option to insert link to original page into article posts created by MyCurator
* Option to set excerpt length for article posts created by MyCurator
* Option to directly enter the WordPress post editor when making a training post Live

= 1.0.7 =
* New option to support manual content curation, which keeps good trained posts on the training page
* Added link to MyCurator support forum on the wordpress.org site
* Fix to ensure deletion of saved pages when corresponding post is deleted

= 1.0.6 =
* A few more Changes to support WordPress plugin repository

= 1.0.5 =
* Changes to support WordPress plugin repository
* Support for multisite installations
* Access to getting an API Key from getting started and options pages

= 1.0.4 =
* Updates to support WordPress 3.4
* Changes to support themes using query arg hooks

