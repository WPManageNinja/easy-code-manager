---
title: Getting Started with FluentSnippets
slug: getting-started
tagline: Getting started with FluentSnippets
sidebar: true
prev: false
next: true
editLink: true
pageClass: docs-home
menu_order: 1
---

## Installation
To install FluentSnippets on your WordPress website, login to your WordPress Website, go to Plugins -> Add New and then Search for "FluentSnippets"

Once you find the plugin, Click Install and then Activate.

## Configure FluentSnippets
There has few different section which you can configure in under a minute.

### General Settings

![General Settings](https://fluentsnippets.com/wp-content/uploads/2023/12/fluent-snippets-general-settings.png)

- **Auto Activate:** You may enable this if you want to activate new snippets automatically. If you disable this then you have to manually activate new snippets after creation.
- **Auto Disable:** FluentSnippets handle snippet errors automatically. So once a snippet has an error, it will be disabled automatically. If you want to alter this behavior then you can disable this feature.

### Safe Mode Settings

![FluentSnippets Safe Mode](https://fluentsnippets.com/wp-content/uploads/2023/12/fluent-snippets-safe-mode.png)

FluentSnippets may automatically disable a snippet on fatal error based your General Settings. There are still situations when you might get locked out by running a snippet that doesn’t throw a fatal error on runtime. 
These scenarios are very rare but in case you run into that or you may just want to temporarily disable all the snippets on your site you can use the safe mode.

There has two-way, you can disable all the custom snippets on your site.

**By Safe Mode URL: **
You can disable all the custom snippets on your site by visiting the Safe Mode URL. The Safe Mode URL is something like this: `https://your-site.com/index.php?fluent_snippets=1&snippet_secret=RANDOM__SECURE_STRING`
The URL will be different for each site and you can find the URL in the Safe Mode Settings section.

**By Safe Mode Constant:**
You can also disable all the custom snippets on your site by defining a constant in your wp-config.php file. The constant is `FLUENT_SNIPPETS_SAFE_MODE` and you have to set the value to `true` to enable the safe mode.

```php
define( 'FLUENT_SNIPPETS_SAFE_MODE', true );
```

#### Standalone Mode

![FluentSnippets Standalone Mode](https://fluentsnippets.com/wp-content/uploads/2023/12/fluent-snippets-standalone-mode.png)

FluentSnippets does not force you to keep installing this plugin all the time. You can disable or uninstall this plugin and still keep running your snippets as a stand-alone mode.

When using standalone mode your scripts will be executed from mu-plugins file.


