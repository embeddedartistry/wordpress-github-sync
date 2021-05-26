When we have markdown content, I see there's the markdown block + the HTML... when we update the markdown block, what happens to the HTML? Is it re-rendered? Why is that duplicated?

- Changed markdown section only: no change on website
- Went to edit the page: block is corrupted. Click: "Repair".
- Now the changes are reflected...
- But we can't auto-repair every time can we?

Current plan: avoid Markdown block on website going forward. We can remove the markdown block comments from the post and keep just the HTML. That will convert the post to a classic block type. 
