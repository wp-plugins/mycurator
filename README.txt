=== MyCurator ===
Contributors: mtilly
Donate link: 
Tags: content curation, curation tools, curation software, curation plugin, content marketing, content management, content discovery, marketing, SEO
Requires at least: 3.3
Tested up to: 3.5
Stable tag: 1.3.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

MyCurator plugin is content curation software that provides fresh content and builds SEO for your WordPress site. 

== Description ==

MyCurator is a professional content curation platform for WordPress sites. 
MyCurator works behind the scenes every day to find new articles, and new inspiration, for your curation efforts. 
Rather than make you search for new content, it pushes personalized, targeted content to the private training page on your site. 
Each article found by MyCurator includes the full text and all images as well as the attribution to the original page, 
right in the WordPress editor.  You can easily grab quotes and images for your curated post, adding your insights 
and comments, quickly creating newly curated content for your site.

Find out more at <a href="http://www.target-info.com/mycurator/" >MyCurator</a>.

* Like a personal assistant, MyCurator saves you hours per day discovering content on the web, pushing it to your private training page
* Simply train with thumbs up/down and weed out off-topic and spam articles
* The full text and all images for each article are available as a meta box in the WordPress post editor.
* Attribution to the original article is automatically inserted into the curated post.
* Curated posts have access to SEO, theme options and other plugins of your environment just as with a regular post
* Get It Bookmarklet allows you to discover content while browsing and add it to your training page for future curation.
* MyCurator finds articles in any language, and all posts are in the native language of your site.
* Works with any RSS source of articles including Google alerts, blogs, news feeds and Twitter searches (follows embedded links).
* Source It Bookmarklet to easily capture feeds.
* You can choose to automatically have the good articles posted to a specific category on your site.
* Or you may choose to manually curate the best insights from the articles into your own site posts.
* Each article is saved as a readable page, like Instapaper, using Diffbot technology.

MyCurator is powerful corporate level software brought to you as a plugin.  It uses a cloud process to perform 
intensive AI processing and article classification.  This allows MyCurator to maintain a low processing overhead on your blog.

MyCurator allows you to curate 2 Topics (equivalent to WordPress Categories) for free, but you will have to obtain an API Key to access the 
cloud services.  For those who need to curate a lot of different topics or for multiple sites, low priced monthly plans are 
available - always with a free 30 day trial.  Visit http://www.target-info.com/pricing/ for more information.

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

