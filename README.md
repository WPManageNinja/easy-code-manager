## FluentSnippets 🚀
#### The High-Performance Code Snippet Plugin for WP

----

[**Website**](https://fluentsnippets.com) | [**Download From WordPress**](https://wordpress.org/plugins/easy-code-manager/)

### Features
- File-based Snippets (0 Database Query) 🚀
- Custom Code Snippets (PHP/HTML/CSS/JS)
- Advanced Conditional Logics
- Automatic Error Handling
- Custom Shortcode
- Stand-alone Mode (keep running your snippets in stand-alone mode. No lock-in, use it whenever you want.)

### Why do we build FluentSnippets?
Long story short, we manage many websites for our products and these are mostly content sites, our content team sometimes needs to add custom functionality for content placement and dynamic content blocks and we need a code snippets plugin as we manage our WP files via Git. We tried to use almost all the existing code snippets plugins and could not choose a single one because of the design decision these plugin authors made. Please read the full post to understand why we had to build a better solution to use our own.

### Design of FluentSnippets
![Design of FluentSnippets](https://fluentsnippets.com/wp-content/uploads/2023/12/fluent-snippets-plugin-design.png)

You see, the design is super simple and this is what it should be! FluentSnippets stores the code snippets in the flat file and uses code blocks in each snippet file to add metadata like a description, title, conditional logic, snippet type, and other things. We also parse these data once and cache these into index.php so we don’t have to parse these code blocks in every request. Then on runtime, it just includes those files to your selected action hook. In the whole process, FluentSnippets runs 0 database queries. In fact, we don’t have any Database query in the whole plugin runtime.

With this native design, FluentSnippets is native, secure by design, and the most performant code snippets in this category.

### The code editor
The code editor of FluentSnippets is simple. We have used codemirror javascript library to add the code editor. It does not have advanced features like auto-complete.
![Code Editor](https://fluentsnippets.com/wp-content/uploads/2023/12/snippet-explained-2048x1362.png)

### Future of FluentSnippets
Every plugin we build, we aim to solve problems for businesses and add immerse values. We are releasing FluentSnippets with the same goal and vision. We will continue imrove, innovate add features the community wants. Few things we are going to add in the next few weeks in this website

- Code Snippets Library powered by the community and plugin authors
- Community Forum for Support and discussions
- Improve the Code Editor to support auto-complete
- Adding more snippet locations
- Adding more conditional logics

You are more than welcome to contribute to the code or the documentation. Or helping us by recommending this to your friends and community. Together, let’s make the WordPress more powerful and secure.

### How to build
- `npm install`
- `npx mix watch` - For development
- `npx mix --production` - For Production
