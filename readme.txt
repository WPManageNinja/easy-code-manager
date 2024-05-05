=== FluentSnippets - The High-Performance file based Custom Code Snippets Plugin ===
Contributors: techjewel
Author URL: https://fluentsnippets.com
Tags: wp codes, functions, custom codes, php codes, code snippets
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0 or higher
Requires PHP: 7.3
Tested up to: 6.5
Stable tag: 10.34

Add header and footer scripts, PHP Snippets, Custom CSS /JS snippets with advanced conditional logic, and more...

== Description ==

Experience unmatched speed and security with a unique file-based code snippet plugin, designed for security & speed

FluentSnippets is the **Most Secure and Performance-Focused** Code Snippet Plugin for WordPress. FluentSnippets store your snippets in flat files, so it does not run SQL queries for your snippets. It is the **Fastest Code Snippet Plugin** for WordPress.
Our mission is to streamline the process of integrating custom code snippets in WordPress, making it safe, secure, fast, and hassle-free.

[youtube https://www.youtube.com/watch?v=kUuW4sY8m7o]

### Why FluentSnippets?

FluentSnippets stores the code snippets in the flat file and uses code blocks in each snippet file to add metadata like a description, title, conditional logic, snippet type, and other things. We also parse these data once and cache these into index.php so we don’t have to parse these code blocks in every request. Then on runtime, it just includes those files to your selected action hook. In the whole process, FluentSnippets runs 0 database queries. In fact, we don’t have any Database query in the whole plugin runtime.
With this native design, FluentSnippets is native, secure by design, and the most performant code snippets in this category.

### Features

- **File-based Snippets:** Your snippets are safely saved in your file system and load natively with zero database queries, so it’s safe, secure, and ultra-fast.
- **Custom Code Snippets:** Write your custom code snippets including PHP, JS, CSS, HTML, and more. Categorize your snippets with groups, tags, etc
- **Advanced Conditional Logic:** Execute code snippets solely under specified conditions like post type, date, URL, user type, and many more.
- **Automatic Error Handling:** The automatic error hander prevents common errors when adding custom snippets to ensure they never break your site.
- **Custom Shortcode:** With custom shortcode of your snippets, you can create custom dynamic content blocks and use them across your site and manage them.
- **Stand-alone Mode:** The most interesting feature is the Stand-alone Mode. With this feature, you can add your snippets, activate the standalone mode, and then you can uninstall and delete the plugin and your snippets will still run via mu-plugins architecture and when you reactivate the plugin you can manage your snippets as before.

[youtube https://www.youtube.com/watch?v=-bQPZ23LSdQ]


### Powerful Smart Conditional Logics
We have added smart conditional logic to let you choose precisely where you want to execute your code. These conditional logics include

- User’s Login State or Role
- Type of Pages
- Post Types
- Taxonomy or Term-Based Rule
- By Page / Post URL
- Target Specific Page / Post / CPT
- Date Based Conditions
- FluentCRM Tag / Lists based rule of the current contact

These conditions are grouped-based, which means you can add multiple groups with a set of conditions and if any of the group match the condions it will execute the snippet.

### Snippet Types
FluentSnippets has four types of snippets. You can choose the snippet type from the snippet type selection.

**Functions – PHP Snippet:** This snippet is for all the PHP code that you need to execute in specific areas like you would write in your theme’s functions.php file.
You can use this snippet type to create functions/classes, hook into other actions and filters, and more.

**Content – PHP + HTML Snippet Type:** This snippet type is used to insert content to different places like header, footer, after-post content, before-post content, etc. You can write php / html / js / css code in this snippet type.

**CSS Snippet Type:** You can use this snippet type to add custom CSS to your site.

**JS Snippet Type:** You can use this snippet type to add custom JS to your site.

### Internal Design of FluentSnippets Plugin

The design is super simple and this is what it should be! FluentSnippets stores the code snippets in the flat file and uses code blocks in each snippet file to add metadata like a description, title, conditional logic, snippet type, and other things. We also parse these data once and cache these into index.php so we don’t have to parse these code blocks in every request. Then on runtime, it just includes those files to your selected action hook. In the whole process, FluentSnippets runs 0 database queries. In fact, we don’t have any Database query in the whole plugin runtime.
With this native design, FluentSnippets is native, secure by design, and the most performant code snippets in this category.


### Popular use cases of this Code Snippet plugin

- Adding custom PHP Code to extend functionalities
- Adding Header and footer codes (Google Analytics / Pixel / Ads codes)
- Custom CSS for specific post/page types
- Custom Javascript codes
- Dynamic Content to different types of places like before/after post content or footer
- Dynamic Shortcode

[youtube https://www.youtube.com/watch?v=5E1w4mGe3xw]

== Other Plugins By The Same Team ==
<ul>
	<li><a href="https://wordpress.org/plugins/fluent-crm/" target="_blank">FluentCRM – Email Marketing, Newsletter, Email Automation and CRM Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/fluentform/" target="_blank">Fluent Forms – Fastest WordPress Form Builder Plugin</a></li>
	<li><a href="https://wordpress.org/plugins/ninja-tables/" target="_blank">Ninja Tables – Best WP DataTables Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/ninja-charts/" target="_blank">Ninja Charts – Best WP Charts Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/wp-payment-form/" target="_blank">WPPayForm - Stripe Payments Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/mautic-for-fluent-forms/" target="_blank">Mautic Integration For Fluent Forms</a></li>
	<li><a href="https://wordpress.org/plugins/fluentforms-pdf/" target="_blank">Fluent Forms PDF - PDF Entries for Fluent Forms</a></li>
	<li><a href="https://wordpress.org/plugins/fluent-smtp/" target="_blank">FluentSMTP - WordPress Mail SMTP, SES, SendGrid, MailGun Plugin</a></li>
</ul>


== CONTRIBUTE ==
If you want to contribute to this project or just report a bug, you are more than welcome. Please check repository from <a href="https://github.com/WPManageNinja/easy-code-manager">Github</a>. FluentSnippets was known as Easy Code Manager before. We have rebuild & rebranded it to FluentSnippets.


== Installation ==

This section describes how to install the plugin and get it working.

0. Just search for FluentSnippets in WordPress Plugins and click install and activate.


== Frequently Asked Questions ==
= What are the differences between FluentSnippets & other code snippets plugins? =
The primary difference is that FluentSnippets is built for speed, security, and ease of use. All other Code Snippet plugins store all the snippets in Database tables so in every WordPress request, they do extensive DB queries to execute them. The method is very slow and dangerous for site security. The snippets can be modified or exploited via SQL injection caused by other plugins.

FluentSnippets solves this very specific problem by storing all the snippets in a flat file and loading the snippets just like any of the feature plugins. So it’s a fast, secure, and native solution for all of your custom code snippets.

= What is the primary function of FluentSnippets and how can it benefit my website? =
The primary function of the FluentSnippets plugin is to allow you to add custom code snippets to your WordPress site easily and without editing your theme’s or child theme’s files directly.

It provides a user-friendly interface to add, manage, and execute custom PHP, HTML, JavaScript, or CSS snippets on your website. This is particularly beneficial as it ensures the sustainability of your code – your custom changes won’t disappear after a theme update.

Whether you need to add a simple CSS tweak, embed custom HTML in the header or footer, or run a complex PHP script, FluentSnippets can handle it, saving you time and making the process safer and more efficient.

= Is it required to have coding knowledge to use this plugin? =
While having some basic coding knowledge can certainly be beneficial when using this plugin, it’s not absolutely necessary. FluentSnippets is designed to be user-friendly and accessible to a wide range of users.

The purpose is to simplify the process of adding custom code to your website. This means you can easily insert custom PHP, JavaScript, HTML, or CSS into your site without editing your theme’s files directly, which can be complex and risky for beginners.

= Will FluentSnippets slow down my site? =
Absolutely not. Unlike other code snippets plugin, FluentSnippets does not use the database to store your custom snippets, it uses flat file-based storing method to store your snippets securely and execute those by loading from the file system which is extremely fast & native. In fact, FluentSnippets does not store or query the database at all.

== Screenshots ==

1. FluentSnippets Dashboard
2. Snippet Editor Screen Overview
3. Settings Overview
4. Architectural Design Comparison

== Changelog ==

= 10.34 - May 05, 2024 =
- Fixed Script Conditions Issues
- Added Loading as File for Snippets
- Fixed PHP 8.x Compatibility Issues

= 10.33 - Jan 25, 2024 =
- Fixed Snippet Error Issue Fixed

= 10.32 - Jan 05, 2024 =
- Fixed FluentCRM Integration
- Added Detailed Error Message on Code Snippets
- Improvement on REST API
- Improved UI & UX

= 10.31 - December 18, 2023 =
- Fixed a typo in the conditional logic
- Added Video Tutorial

= 10.3 - December 18, 2023 =
- New design and Branding
- New UI & UX

= 10.1 =
* Re-Write the Plugin for better performance
* New UI & UX

= 10.0 =
* Fix: Styling fixes for WordPress version 5.5
* Enhancement: Updated ACE Editor to v1.4.12
* Enhancement: Improve snippet manager
* Enhancement: Add placeholder option
* Added: Option to disable auto-indent
* Added: New language modes
* Fix: Double space being converted to dot on mobile keyboards
* Fix: Backspace not working with some mobile keyboards

== Upgrade Notice ==
