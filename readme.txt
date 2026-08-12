=== FluentSnippets – High-Performance Code Snippets, Header & Footer Code, Custom CSS & PHP Code Manager ===
Contributors: techjewel
Author URL: https://fluentsnippets.com
Tags: code snippets, header footer, php, custom css, functions php
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0
Requires PHP: 7.3
Tested up to: 7.0
Stable tag: 10.56

Fast, file-based code snippets plugin. Insert header & footer scripts and add PHP, CSS, JS & HTML snippets with conditional logic. Zero DB queries.

== Description ==

FluentSnippets is the **fastest and most secure code snippets plugin** for WordPress, a high-performance way to add custom code to your site without editing your theme's functions.php file. Easily **insert header and footer scripts**, run **PHP code snippets**, and add **custom CSS, JavaScript, and HTML** with powerful conditional logic.

⭐ **100% free and open source. No "Pro" version, no upsells, no feature held back behind a paywall.**

Unlike every other code snippet plugin, FluentSnippets stores your snippets in flat files and loads them natively, so it runs **zero database queries** and never slows down your site. No bloated database tables, no extra SQL injection surface, just safe, native, lightning-fast code execution.

Whether you want to add Google Analytics, a Facebook Pixel, ads.txt or banner ad codes, conversion tracking scripts, custom CSS tweaks, or complex PHP functionality, FluentSnippets makes it safe, secure, fast, and hassle-free. It is the perfect lightweight alternative to editing functions.php or using heavier, database-driven code snippet managers.

👉 Website: [Official Website](https://fluentsnippets.com/)
👉 Community: [Join Community](https://community.wpmanageninja.com/portal/)
👉 Github: [View source & contribute](https://github.com/WPManageNinja/easy-code-manager)

### Simple for Beginners, Powerful for Developers

Adding a snippet takes seconds: open the editor, paste or write your code, choose where it should run (everywhere, admin only, front-end only, or based on conditional logic), and save. A built-in syntax highlighter and smart error handling guide you along the way. If a snippet ever causes a fatal error, FluentSnippets automatically deactivates it so your site stays online instead of breaking. You can organize snippets with tags, switch them on or off with a single click, and export or import them between sites in seconds.

### 100% Free & Open Source: No Upsells, No "Pro" Version

FluentSnippets is completely free and fully open source under the GPL. There is no premium version, no locked or "Pro-only" features, no upsells, and no email-gated downloads. Every single feature is included for everyone, on every site, forever. The full source code lives on [GitHub](https://github.com/WPManageNinja/easy-code-manager), so developers can read the code, audit it for security, request features, report bugs, or contribute directly via pull requests. It is built and actively maintained by the team behind FluentCRM, Fluent Forms, and FluentSMTP.

### From Quick Tweaks to Advanced Customizations

From simple tweaks to advanced development, FluentSnippets handles it all. Use it to add Google Tag Manager and tracking pixels, register custom shortcodes, create custom functions, disable comments or emojis, remove unwanted admin notices, add JSON-LD schema markup, or run any PHP snippet you would normally drop into functions.php. CSS snippets even support the WordPress Block Editor (Gutenberg), so you can style your site with a familiar editing experience. Every snippet runs only where you tell it to, thanks to granular conditional logic based on post type, page URL, user role, login status, and more, so your custom code stays fast, targeted, and easy to manage.

[youtube https://www.youtube.com/watch?v=kUuW4sY8m7o]

### How FluentSnippets Works: Zero Database Queries

Every other code snippets plugin keeps your code in the database and queries it on every single page load. FluentSnippets does not. Each snippet is stored as a real PHP file, with its title, description, snippet type and conditional logic recorded in a doc block at the top of that file. Those doc blocks are parsed once and cached into an index, so nothing is re-parsed on later requests. At runtime the plugin simply includes the files you activated, on the action hook you chose, exactly the way WordPress loads a plugin.

The result is **zero database queries at runtime**. Not "a few". None at all. There is no snippets table to bloat your database, nothing for a SQL injection in some other plugin to reach, and no query for your host to slow down. It is a native, secure-by-design architecture, and it makes FluentSnippets the fastest code snippets plugin in its category.

### Features

- **File-based Snippets:** Your snippets are safely saved in your file system and load natively with zero database queries, so it’s safe, secure, and ultra-fast.
- **Custom Code Snippets:** Write your custom code snippets including PHP, JS, CSS, HTML, and more. Categorize your snippets with groups, tags, etc
- **Advanced Conditional Logic:** Execute code snippets solely under specified conditions like post type, date, URL, user type, and many more.
- **Automatic Error Handling:** If a snippet ever triggers a fatal error, the error handler catches it, deactivates that one snippet, and tells you which line caused it, so a bad snippet never takes your site down.
- **Safe Mode Recovery:** If custom code ever locks you out of wp-admin, a Safe Mode URL switches every snippet off so you can get back in and fix it. No FTP, no database surgery.
- **Export & Import:** Move snippets between sites, or keep a portable backup of them, in a couple of clicks.
- **Block Editor Support:** CSS snippets work inside the WordPress Block Editor (Gutenberg), so styling your site happens in a familiar place.
- **Custom Shortcode:** With custom shortcode of your snippets, you can create custom dynamic content blocks and use them across your site and manage them.
- **Stand-alone Mode:** The most interesting feature is the Stand-alone Mode. With this feature, you can add your snippets, activate the standalone mode, and then you can uninstall and delete the plugin and your snippets will still run via mu-plugins architecture and when you reactivate the plugin you can manage your snippets as before.
- **Dark Mode:** A full dark theme across every screen, switchable from the admin bar and remembered between visits. The setting is shared with the other Fluent plugins, so switching it in one switches it in all of them.

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

Conditions are group-based: you can add several groups, each with its own set of rules, and the snippet runs whenever any one group matches.

### Snippet Types
FluentSnippets has four types of snippets. You can choose the snippet type from the snippet type selection.

**Functions – PHP Snippet:** This snippet is for all the PHP code that you need to execute in specific areas like you would write in your theme’s functions.php file.
You can use this snippet type to create functions/classes, hook into other actions and filters, and more.

**Content – PHP + HTML Snippet Type:** This snippet type is used to insert content to different places like header, footer, after-post content, before-post content, etc. You can write php / html / js / css code in this snippet type.

**CSS Snippet Type:** You can use this snippet type to add custom CSS to your site.

**JS Snippet Type:** You can use this snippet type to add custom JS to your site.

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
= Can I insert header and footer scripts with FluentSnippets? =
Yes. FluentSnippets lets you easily insert code into your site's header and footer, such as Google Analytics, Facebook Pixel, Google Tag Manager, ad codes, and any other custom HTML, JavaScript, or tracking scripts. You can add them globally or only on specific pages using conditional logic.

= What types of code snippets can I add? =
You can add PHP code snippets (just like your theme's functions.php), Content snippets (PHP + HTML for header, footer, before/after post content, and more), Custom CSS snippets, and Custom JavaScript snippets, all from one clean interface with syntax highlighting.

= Is FluentSnippets a good alternative to other code snippet plugins? =
Absolutely. Most code snippet plugins store your snippets in the database and run SQL queries on every page load, which is slow and increases your attack surface. FluentSnippets stores everything in flat files and loads them natively with zero database queries, making it a faster, safer, and more lightweight alternative for managing custom code.

= Can I use FluentSnippets instead of editing functions.php? =
Yes. FluentSnippets is the safe way to add custom code without touching your theme's functions.php file. Your snippets survive theme updates, can be activated or deactivated with one click, and the built-in error handler prevents a bad snippet from breaking your site.

= Where are my code snippets stored? =
Each snippet is saved as a real PHP file in `wp-content/fluent-snippet-storage`, not in a database table. That means you can read them, back them up with your normal file backups, and move them between sites by copying files. If you need them somewhere else, the `FLUENT_SNIPPETS_STORAGE_DIR` constant in `wp-config.php` will point the plugin at a different directory. Your snippets also survive theme changes and theme updates, because nothing lives in your theme.

= What happens if a code snippet breaks my site? =
FluentSnippets watches for fatal errors. If a snippet causes one, the plugin deactivates that specific snippet automatically and reports which snippet and which line was responsible, so your site stays online instead of showing a white screen. If you ever do get locked out of wp-admin, a Safe Mode URL switches every snippet off at once so you can get back in. No FTP access or database editing required.

= Can my snippets keep running if I delete the plugin? =
Yes. Turn on Stand-alone Mode and your snippets keep running through the mu-plugins architecture even after the plugin is deactivated and deleted. Reinstall FluentSnippets later and you can manage them again exactly as before.

= Can I export and import snippets between sites? =
Yes. You can export your snippets to a file and import them into another WordPress site in a couple of clicks. That is useful for reusing the same set of customizations across sites, or for keeping a portable backup.

= What is the primary function of FluentSnippets and how can it benefit my website? =
The primary function of the FluentSnippets plugin is to allow you to add custom code snippets to your WordPress site easily and without editing your theme’s or child theme’s files directly.

It provides a user-friendly interface to add, manage, and execute custom PHP, HTML, JavaScript, or CSS snippets on your website. This is particularly beneficial as it ensures the sustainability of your code – your custom changes won’t disappear after a theme update.

Whether you need to add a simple CSS tweak, embed custom HTML in the header or footer, or run a complex PHP script, FluentSnippets can handle it, saving you time and making the process safer and more efficient.

= Is it required to have coding knowledge to use this plugin? =
While having some basic coding knowledge can certainly be beneficial when using this plugin, it’s not absolutely necessary. FluentSnippets is designed to be user-friendly and accessible to a wide range of users.

The purpose is to simplify the process of adding custom code to your website. This means you can easily insert custom PHP, JavaScript, HTML, or CSS into your site without editing your theme’s files directly, which can be complex and risky for beginners.

= Will FluentSnippets slow down my site? =
Quite the opposite. Unlike other code snippets plugins, FluentSnippets does not use the database to store your custom snippets. It stores them as flat files and executes them natively from the file system, which is extremely fast. It runs no database queries at all while your site is serving pages.

== Screenshots ==

1. All your code snippets in one place, grouped into folders and filtered by type: PHP, Content (PHP + HTML), CSS and JavaScript.
2. The snippet editor: syntax highlighting, the name, description, group, priority and tags, and a choice of where the snippet runs.
3. Conditional logic: run a snippet only on the post types, URLs, user roles or login states you choose.
4. Settings, including the Safe Mode URL that switches every snippet off if you are ever locked out, and Standalone Mode.
5. Dark mode, on every screen.
6. How it works: other plugins keep your snippets in database tables and query them on every request. FluentSnippets stores each snippet as a flat file and includes it natively, so there is nothing to query.
7. A tour of the editor: pick the snippet type, write the code, choose where it runs, and organize it with groups and tags.

== Changelog ==

= 10.56 - Aug 12, 2026 =
- Added a dark mode, switchable from the admin bar and shared with the other Fluent plugins
- Redesigned every screen: the snippet list, the editor, Settings and About
- Improved colour contrast throughout, so every screen meets WCAG AA in both light and dark
- Fixed a click in the code editor selecting the whole snippet instead of placing the cursor
- Fixed error text on the snippet list not showing in the error colour
- Added inactive snippets count and filter reset options when no snippets match the current filter
- Fixed the welcome screen being skipped on a fresh install when "Hide Inactives" was enabled
- Fixed a JavaScript error on the dashboard when the site has no snippets
- Fixed pagination total count so sites with more than 200 snippets can reach every page
- Changed Color Schema and new branding for FluentSnippets

= 10.55 - Jun 28, 2026 =
- Added "Hide Inactives" filter to quickly hide inactive snippets on the dashboard
- Added Custom Snippets Storage Path support via constant
- Invalidate OPcache after writing snippet engine files for reliable updates
- Fixed RTL code formatting issue in the editor
- Fixed string concatenation issue
- UI / UX Improvements

= 10.53 - Jun 22, 2026 =
- Fixed Block Editor CSS Issue

= 10.52 - Jan 21, 2026 =
- Added Block Editor Support for CSS
- Use Ajax endpoint to create and edit snippets for better Usability
- Enhance handleShortcode to retain user arguments
- UI / UX Improvements

= 10.51 - May 11, 2025 =
- Security: Added nonce verification for export/import snippets (thanks to Patchstack)
- Conditional Logic "User / logged in" issue fixed
- Date issue fixed on create new snippet

= 10.50 - Apr 27, 2025 =
- Added Export Import Features
- Fixed issues with Site Migration
- Added i18n Strings to cover almost all the strings
- Added Soft Wrap for Editor
- Added Support for command + s for saving snippets

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

= 10.56 =
A redesign of every screen, plus a new dark mode you can switch from the admin bar. Includes accessibility and contrast improvements throughout and several fixes to the snippet list, editor and pagination. Your snippets are untouched by this update.
