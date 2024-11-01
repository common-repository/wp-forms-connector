=== WP-Forms-Connector ===

Contributors: Surendra
Tags: cf7, contact form 7, contact form 7 db, contact form db, contact form seven, contact form storage, export contact form, save contact form, wpcf7, contact form7 api, contact form rest api form list, contact form rest api form value
Donate link: #
Requires at least: 4.8
Tested up to: 6.2
Stable tag: 1.2.4.6
Requires PHP: 7.2
License: GPLv2 or later

#### WP REST API V1 V2 V3 #####
Save and manage Contact Form 7 messages and never lose your important data. It is a lightweight contact form 7 database plugin that helps you collect and store data easily.


== Description ==

The "WP Form Connector" plugin saves contact form 7 submissions to your WordPress database, further allowing you to export the data in a CSV file format.

By simply installing the plugin, it will automatically begin to capture form submissions from contact form 7.

= Features of WP Form Connector =

* No configuration needed
* Save Contact Form 7 submitted data to the database
* Single database table for all contact form 7 forms
* Easy to use and lightweight plugin
* Developer friendly & easy to customize
* Display all created contact form 7 forms list
* Export Form data in CSV file
* Contact Form 7 API
 

Support : [https://www.appypie.com/contact-us](https://www.appypie.com/contact-us)

== Installation ==

1. Download and extract plugin files to a wp-content/plugin directory
2. Activate the plugin through the WordPress admin interface
3. You are all done!


Website: http://www.appypie.com

Support: support@appypie.com

== Screenshots ==
1. Click admin Forms Connector show all form List, Screenshot-1.png
2. Click form name,Screenshot-2.png
3. Click form value show detail page, Screenshot-3.png

= How use Rest Api Process =
1. Get creted number of form list on Rest Api,(http://www.example.com/wp-json/form/v1/api/)
2. Get form value post form ID on Rest Api,(http://www.example.com/wp-json/form/v1/jsonapi/formID)
3.comment api
https://example.com/wp-json/wp/v3/comment/list?after=xxxx-xx-xx:xx:xx:xx&order=DESC&limit=100
https://example.com/wp-json/wp/v3/comment/list/<id>
https://example.com/wp-json/wp/comment/detail/<id>
https://example.com/wp-json/wp/comment/delete/<id>
User Create API
http://www.example.com/wp-json/wp/v3/user/create
4. Post Related API
Create Post API
http://www.example.com/wp-json/wp/v3/post/create
Post Listing API
http://www.example.com/wp-json/wp/v3/post/list?after=xxxx-xx-xx:xx:xx:xx&order=DESC&limit=100
Post Detail API
http://www.example.com/wp-json/wp/v3/post/list/id
Post Delete API
http://www.example.com/wp-json/wp/v3/post/delete/id

5. Create Pages API
http://www.example.com/wp-json/wp/v3/pages/create
pages Listing API
http://www.example.com/wp-json/wp/v3/pages/list?after=xxxx-xx-xx:xx:xx:xx&order=DESC&limit=100
pages Detail API
http://www.example.com/wp-json/wp/v3/pages/list/id
pages Delete API
http://www.example.com/wp-json/wp/v3/pages/delete/id

6. Category Related API
Category Listing API
http://www.example.com/wp-json/wp/v3/category/list

7. User Listing API
http://www.example.com/wp-json/wp/v3/user/list?after=xxxx-xx-xx:xx:xx:xx&order=DESC&limit=100

User Listing Detail API
http://www.example.com/wp-json/wp/v3/user/list/3
User Listing Delete API
http://www.example.com/wp-json/wp/v3/user/delete/3

8. Get all post Type list
http://www.example.com/wp-json/wp/v3/allposttype 

9. Get wpform data
wpform list 
http://www.example.com/wp-json/wpform/v2/data

Get wpform form value by ID
https://nftnews.pbodev.info/wp-json/wpform/v2/jsondada/633?from_date=2023-07-31 10:01:09&limit=1

10. Pass header on authentication, username, password, mandatory,

== Changelog ==
= 1.0.0 =

First version of plugin.
= 1.1.6 =
Fixed minor bugs
Add action hooks
= 1.1.7 =
Add filter hooks
Multisite support

= 1.1.9 =
Fixed Sorting bugs

== 1.2 ==
Fixed csv export bug

== 1.2.1 ==
Multisite network bug fixed 

== 1.2.2 ==
Added cfdb7_access capability

== 1.2.3 ==
Fixed csv export issue 

== 1.2.4 ==
Fixed admin notification bug

== 1.2.4.3 ==
Responsive issue fixed 

== 1.2.4.6 ==
Optimized csv export memory usage 
