---
title: Create Your First Snippet
slug: create-snippet
tagline: Create Your First Snippet with FluentSnippets
sidebar: true
prev: true
next: true
editLink: true
pageClass: docs-create
menu_order: 1
---

Ready to add your first code snippet with FluentSnippets? Follow this guide to get started.

## Adding a new snippet
Get started by logging in to your WordPress admin. In that area look for “FluentSnippets” in the admin left sidebar menu and navigate to it and you can see all your snippets.

![FluentSnippets Menu](https://fluentsnippets.com/wp-content/uploads/2023/12/all-snippets-screen.png)

Click on the “New Snippet” button to create a new snippet. 

![FluentSnippets New Snippet](https://fluentsnippets.com/wp-content/uploads/2023/12/snippet-explained.png)

You will see a screen like the above screenshot. Now you can add your snippet code in the "Snippet Code" field. You can also add a title and description for your snippet. Don't forget to click on the "**Save Snippet**" button and then **activate** to save and run your snippet.

## Snippet Types

FluentSnippets has four types of snippets. You can choose the snippet type from the snippet type selection.

**Functions - PHP Snippet**

This snippet is for all your PHP code where you need to execute in specific area like you would write in your theme's functions.php file. 

You can use this snippet type to create functions / class, hook into other actions and filters, and more. 

**Content - PHP + HTML Snippet Type**

This snippet type is used to insert content to different places like header, footer, after post content, before post content, etc. You can write php / html / js / css code in this snippet type.

You can also use this snippet type to create shortcodes.

*Use Cases:*

- Add Analytics code to the header/footer
- Add banner before/after post content
- Add content just after body tag
- Creating dynamic shortcodes

**CSS Snippet Type**

You can use this snippet type to add custom CSS to your site.

**JS Snippet Type**

You can use this snippet type to add custom JS to your site.

## Snippet Location - Where to run

You can choose where you want to run your snippet. Depending on the snippet type you will see different options.

**Snippet Locations**

| **Location Name**       | **Description**                                                               | **Available In**         |
|-------------------------|-------------------------------------------------------------------------------|--------------------------|
| Run Everywhere          | The snippet will run everywhere both backend and fronent                      | Functions (php)          |
| Admin Only              | The Snippet will only run on /wp-admin area                                   | Functions (php), CSS, JS |
| Frontend Only           | The Snippet will run on frontend                                              | Functions (php), JS, CSS |
| Site Wide Header        | It will include the snippet in the head tag of your site                      | content (PHP + HTML), JS |
| Site Wide Footer        | It will include the snippet in the footer of your site                        | content (PHP + HTML), JS |
| Site Wide Body Open     | It will include the snippet just after body tag opening                       | content (PHP + HTML)     |
| Before Content          | Snippet will be included just before the single post/page/cpt's post content  | content (PHP + HTML)     |
| After Content           | Snippet will be included after the single post/page/cpt's post content        | content (PHP + HTML)     |
| Shortcode               | Create dynamic shortcode from your snippet and print anywhere you want        | content (PHP + HTML)     |
| Both Backend & Frontend | Available in CSS type snippets to add custom css styles                       | Styles (CSS)             |


## Advanced Conditional Logic

The Advanced Conditional Logic allows you to create powerful rules to run the snippet based on a set of rules. 

You can create groups of conditions that will all have to be true for the snippet to run. “OR” rules to add multiple groups of such combination of conditional rules.

![FluentSnippets Advanced Conditional Logic](https://fluentsnippets.com/wp-content/uploads/2023/12/conditiona-group-fluent-snippets.png)

The above screenshot shows an example of a conditional group that will run the snippet if the post type is post,page OR the page type is the homepage.

## Snippet Grouping - Virtual Folder

You can group your snippets by creating a snippet group. You can create a snippet group from the snippet group section. The same grouped snippets will be shown together in the snippet list page. This works as a virtual folder for your snippets.

## Snippet Tags

You can add multiple tags to your snippet for easily filter and find your snippets from all snippets page.
