=== Automatic AI Alt-Text ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: alt text, image seo, accessibility, chatgpt, claude
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writes alt text and titles for your images with ChatGPT or Claude: automatically for new uploads, in safe batches for your existing library, with a full history you can edit or undo.

== Description ==

Alt text describes an image for people using screen readers, and for search engines. Most sites have hundreds of images without it. Automatic AI Alt-Text looks at each image with the AI you choose and writes a short, plain description, plus a proper title if you like ("Fresh Strawberries in Crates" instead of "IMG_5102").

**Your AI, your key**

* ChatGPT (OpenAI) or Claude (Anthropic), with your own API key
* Pick the model, with a rough cost per 1,000 images next to each one, or type any other model ID
* Keys are encrypted before they're stored (or set in wp-config.php), and never shown again
* Nothing is sent until "Save & test connection" passes: it sends one real image and shows you what came back

**New uploads**

* New images get alt text automatically, wherever they're uploaded: the Media Library, the block and classic editors, WooCommerce products, page builders and imports
* In the background (uploads stay exactly as fast as now) or right away during the upload, so the alt text is already there when the image lands in the editor
* A daily limit, so an import or migration that adds thousands of images can't quietly run up a bill
* Alt text someone already wrote is never replaced
* Images visitors send through front-end forms are left alone

**Your existing library: Bulk Generate**

* Fill in every image that has no alt text, or rewrite all of them
* Locked until you've tested your settings on 5 images and approved the results
* Batches of 100, 250 or 500 (or everything, with an extra confirmation), with a cost estimate from your own test run
* Runs in your browser with live results and a Stop button, so it never hits a server time limit

**History**

Every change the AI makes is listed with the image, the text before and after, the model and the cost. Edit any of it in place, put the old text back with Undo (one image or several at once), or have the AI write it again.

**Your pages**

The block and classic editors copy alt text into a post when an image is inserted, so images inserted before they had alt text stay empty in your posts. The plugin adds the new alt text to those images as the page is shown, without editing your posts. Alt text typed into a post always wins, and images marked as decorative are left alone. Featured images, galleries, WooCommerce and anything else that reads the Media Library pick it up automatically.

**Page builders**

The plugin is designed for the block and classic editors. Page builders keep their own copy of each image's settings, so images inserted with a builder may not get the AI's alt text on your pages; the settings page tells you which builders it found and what that means for each. Elementor's Image widget and Beaver Builder's Photo module read alt text from the Media Library every time a page is shown, so those do pick it up.

**Your instructions**

Rewrite the instructions the AI follows in Advanced: what to focus on, what to leave out, the tone, your product names. Choose the language, the longest alt text, how large an image to send and whether the page title and file name go along as hints.

**Fast and out of the way**

Nothing is added to your pages' code. Visitors' page loads don't call any AI service, and the alt text filling reuses data WordPress already loads, with no extra database queries.

**Cost**

Each image is a separate paid request to OpenAI or Anthropic, billed to your own account. With the cheapest models that's well under a cent per image, but it adds up across a large library, and the cost is your responsibility. The plugin shows estimates everywhere and strongly suggests batches of 100 or 500.

Find it under Content Powered > Automatic AI Alt-Text in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Privacy =

A reduced copy of each image, its file name and the title of the page it was uploaded to are sent to the AI provider you choose. Suggested text is added to Settings > Privacy.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.
* ChatGPT is a trademark of OpenAI. Claude is a trademark of Anthropic. This plugin isn't affiliated with or endorsed by either.

== Installation ==

1. Upload the `automatic-ai-alt-text` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Automatic AI Alt-Text, choose ChatGPT or Claude, paste your API key, choose a model and click "Save & test connection".
4. To fill in images already in your library, go to the Bulk Generate tab and run the 5-image test.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= How much does it cost? =

The plugin is free. The AI is billed by OpenAI or Anthropic to your own account. The cheapest models (Claude Haiku, GPT-6 Luna) cost around $0.20 per 1,000 images at the medium image size; the most capable ones cost several dollars per 1,000. After the 5-image test, the Bulk Generate tab shows your real average cost per image and an estimate for each batch.

= Will it overwrite alt text I wrote? =

No. New uploads and the default Bulk Generate scope only fill in images with empty alt text. Rewriting existing alt text is a separate choice in Bulk Generate, and every change can be undone from the History tab.

= Does it work with Elementor, Divi or Beaver Builder? =

The alt text is always saved to the Media Library. Elementor's Image widget and Beaver Builder's Photo module read it from there each time a page is shown, so they pick it up. Divi, Avada, WPBakery and other builders may keep their own copy from when the image was placed, so check the alt text in the builder's image settings.

= Where do I put my API key in wp-config.php? =

`define( 'AUTOMATIC_AI_ALT_TEXT_OPENAI_KEY', 'sk-...' );` or `define( 'AUTOMATIC_AI_ALT_TEXT_ANTHROPIC_KEY', 'sk-ant-...' );`. A key there overrides one saved on the settings page.

= What happens if my key stops working or my account runs out of credit? =

Everything pauses straight away, so nothing keeps sending failing requests, and a notice explains what went wrong. Fix it and click "Save & test connection" to carry on; queued uploads are picked up again.

= Does it slow my site down? =

No. Uploads stay as fast as before in the default background mode, and visitors' page loads never call an AI service.

= What happens when I delete the plugin? =

Its settings, encrypted keys and history are removed. All the alt text and titles it wrote stay on your images.

== Changelog ==

See https://github.com/jmsprsns/automatic-ai-alt-text/releases for the full release history.

= 1.0.0 =
* First release.
