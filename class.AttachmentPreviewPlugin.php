<?php
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.file.php');
require_once (INCLUDE_DIR . 'class.format.php');
require_once ('config.php');

/**
 * Provides in-line attachments, and an interface to the DOM wrapper.
 *
 * Read the wiki for more.
 *
 * TODO: Figure out dispatcher, so I can include CS/
 *
 * @return string
 */
class AttachmentPreviewPlugin extends Plugin {

  var $config_class = 'AttachmentPreviewPluginConfig';

  /**
   * What signal are we going to connect to?
   *
   * @var unknown
   */
  const signal_id = 'attachments.wrapper';

  /**
   * An array of received elements with instructions.
   *
   * @var array
   */
  static $foreign_elements;

  /**
   * You will want this off!
   *
   * It will post an error log entry for every single request.. which get's heavy.
   *
   * @var string
   */
  const DEBUG = FALSE;

  /**
   * The PJAX defying XML prefix string
   *
   * @var string
   */
  const xml_prefix = '<?xml encoding="UTF-8" />';

  /**
   * The PJAX defying XML prefix string removal regex..
   *
   * @var string
   */
  const remove_prefix_pattern = '@<\?xml encoding="UTF-8" />@';

  /**
   * An array of messages to be logged.
   * This plugin is called before $ost is fully loaded, so it's likely/possible
   * that actually sending $ost->logDebug $ost->logError etc isn't possible.
   *
   * @var array
   */
  private $messages;

  /**
   * A string of HTML to be appended madly to the end of the normal output.
   *
   * @var string
   */
  private $appended;

  private $limit;

  /**
   * Try and do as little as possible in the bootstrap function, as it is called on every page load, before the system
   * is even ready to start deciding what to do.
   *
   * I'm serious.
   *
   * $ost is what starts this, but it does it during it's own bootstrap phase,
   * so we don't actually have access to all functions that $ost has yet.
   *
   * {@inheritdoc}
   *
   * @see Plugin::bootstrap()
   */
  function bootstrap() {
    // Assuming that other plugins want to inject an element or two..
    // Provide a connection point to the attachments.wrapper
    Signal::connect(self::signal_id,
      function ($object, $data) {
        $this->debug_log("Received connection from %s", get_class($object));
        // Assumes you want to edit the DOM with your structures, and that you've read the docs.
        // Just save them here until the page is done rendering, then we'll make all these changes at once:
        self::$foreign_elements[get_class($object)] = $data;
      });

    // Load our Admin defined settings..
    $config = $this->getConfig();

    // Check what our URI is, if acceptable, add to the output.. :-)
    // Looks like there is no central router in osTicket yet, so I'll just parse REQUEST_URI
    // Can't go injecting this into every page.. we only want it for the actual ticket pages & Knowledgebase Pages
    if (self::isTicketsView() && $config->get('attachment-enabled')) {
      $this->debug_log("Running plugin attachments.");
      // We could hack at core, or we can simply capture the whole page output and modify the HTML then..
      // Not "easier", but less likely to break core.. right?
      // There appears to be a few uses of ob_start in the codebase, but they stack, so it might work!
      ob_start();

      // This will run after everything else, empty the buffer and run our code over the HTML
      // Then we send it to the browser as though nothing changed..
      register_shutdown_function(
        function () {
          // Output the buffer
          // Check for Attachable's and print
          // Note: This also checks foreign_elements
          print $this->inlineAttachments(ob_get_clean());
        });
    }

    // There won't be any foreign elements after the first one has finished, as it deletes them after processing
    // Therefore, these must be sent after, or, or the first one isn't applicable.
    // The API stands seperate to the ostensible purpose of the plugin.
    if (count(self::$foreign_elements)) {

      $this->debug_log("Found %d other users of API.",
        count(self::$foreign_elements));

      // There appears to work to do as signalled.. This would otherwise be ignored as the shutdown handler
      // is nominally only initiated when enabled.. This allows other plugins to send it jobs. ;-)
      ob_start();
      register_shutdown_function(
        function () {
          print $this->doRemoteWork(ob_get_clean());
        });
    }

    // See if there was any HTML to be appended.
    register_shutdown_function(
      function () {
        if ($this->appended)
          print $this->appended;
      });
  }

  /**
   * Plugin seems to want this.
   */
  public function getForm() {
    return [];
  }

  public function getSignalID() {
    return self::signal_id;
  }

  /**
   * ********************************************************** Private Class Functions *******************
   */

  /**
   * Builds a DOMDocument structure representing the HTML, checks the links within for Attachments, then
   * builds inserts inline attachment objects, and returns the new HTML as a string for printing.
   *
   * @param string $html
   * @return string $html
   */
  private function inlineAttachments($html) {
    if (! $html) {
      $this->debug_log("Received no HTML, returned none..");
      // Something broke.. we can't even really recover from this, hopefully it wasn't our fault.
      // If this was called incorrectly, actually sending HTML could break AJAX or a binary file or something..
      // Error message therefore disabled:
      return '';
      // return '<html><body><h3>:-(</h3><p>Not sure what happened.. something broke though.</p></body></html>';
    }

    // We'll need this..
    $config = $this->getConfig();

    // Determine what method to run for each extension type:
    $pdf = [
      'pdf' => 'addPDF'
    ];

    $images = [
      'bmp' => 'addIMG',
      'svg' => 'addIMG',
      'gif' => 'addIMG',
      'png' => 'addIMG',
      'jpg' => 'addIMG',
      'jpeg' => 'addIMG'
    ];
    $higher_risk = [
      'csv' => 'addTEXT',
      'txt' => 'addTEXT',
      'html' => 'addHTML'
    ];

    $audio = [
      'wav' => 'addAudio',
      'mp3' => 'addAudio'
    ];

    $allowed_extensions = [];

    // Merge the arrays together as per instruction..
    switch ($config->get('attachment-allowed')) {
      case 'all':
        $allowed_extensions = $higher_risk + $pdf + $images + $audio;
        break;
      case 'pdf':
        $allowed_extensions = $pdf;
        break;
      case 'image':
        $allowed_extensions = $images;
        break;
      case 'pdf-image':
        $allowed_extensions = $pdf + $images;
        break;
      default:
        $allowed_extensions = array();
    }

    // If the box is ticked, add em, if they want em all, assume they want video too..
    // This doesn't mean youtube will be embedded, but attachments will.
    if ($config->get('attachment-video') ||
       $config->get('attachment-allowed') == 'all') {
      foreach ([
        'mp4',
        'ogv',
        'webm',
        '3gp',
        'flv'
      ] as $f) {
        $allowed_extensions[$f] = 'addVideo';
      }
    }

    if (! count($allowed_extensions)) {
      $this->debug_log("Not allowed to do anything, not doing anything.");
      // We've not been granted permission to change anything, so don't... just return original HTML.
      return $html;
    }

    // Let's not get regex happy.. we all have the tendency.. :-)
    $dom = $this->getDom($html);

    $this->number = 0;
    $this->limit = $config->get('show-initially');

    // Find all URLs: http://stackoverflow.com/a/29272222
    foreach ($dom->getElementsByTagName('a') as $link) {

      // Check the link points to osTicket's "attachments" provider:
      // osTicket uses http://domain.tld/file.php for all attachments,
      // even /scp/ ones. Upgraded to faster strpos from preg_match
      if (strpos($link->getAttribute('href'), '/file.php') !== FALSE) { // Patch issue with subdomains..

        // Luckily, the attachment link contains the filename.. which we can use!
                                                                        // Grab the extension of the file from the filename:
        $ext = $this->getExtension($link->textContent);
        $this->debug_log("Attempting to add $ext file.");

        // See if admin allowed us to inject files with this extension:
        if (! $ext || ! isset($allowed_extensions[$ext])) {
          continue;
        }

        // Find the associated method to add the attachment: (defined above, eg: csv => addTEXT)
        $func = $allowed_extensions[$ext];

        // Just because we've defined the association, doesn't mean we've defined the method,
        // check it:
        if (method_exists($this, $func)) {
          // Call the method to insert link as an attachment:
          call_user_func(
            [
              $this,
              $func
            ], $dom, $link);
        }
      }
      elseif ($config->get('attachment-video')) {
        // This link isn't to /file.php & admin have asked us to check if it is a youtube link.
        // The overhead of checking strpos on every URL is less than the overhead of checking for a youtube ID!
        if (strpos($link->getAttribute('href'), 'youtub') !== FALSE) {
          $this->addYoutube($dom, $link);
        }
      }
    }

    // Before we return this, let's see if any foreign_elements have been provided by other plugins, we'll insert them.
    // This allows those plugins to edit this plugin.. seat-of-the-pants stuff!
    if (count(self::$foreign_elements)) {
      $this->processRemoteElements($dom); // Handles the HTML generation at the end.
    }

    // Check for failure to generate HTML
    // DOMDocument::saveHTML() returns null on error
    $new_html = $dom->saveHTML();

    // Remove the DOMDocument make-happy encoding prefix:
    if (self::isPjax())
      $new_html = preg_replace(self::remove_prefix_pattern, '', $new_html);

      // just return the original if error
    return $new_html ?: $html;
  }

  /**
   * Converts HTML into a DOMDocument.
   *
   * @param string $html
   * @return \DOMDocument
   */
  private function getDom($html = '') {
    static $dom;
    if (! $dom) {
      $dom = new \DOMDocument('1.0', 'UTF-8');
      // Turn off XML errors.. if only it was that easy right?
      $dom->strictErrorChecking = FALSE;
      libxml_use_internal_errors(true);
    }

    if (! $html) {
      return $dom;
    }

    // Because PJax isn't a full document, it kinda breaks DOMDocument
    // Which expects a full document! (You know with a DOCTYPE, <HTML> <BODY> etc.. )
    if (self::isPjax() &&
       (strpos($html, '<!DOCTYPE') !== 0 || strpos($html, '<html') !== 0)) {
      // Prefix the non-doctyped html snippet with an xml prefix
      // This tricks DOMDocument into loading the HTML snippet
      $html = self::xml_prefix . $html;
    }
    // Convert the HTML into a DOMDocument, however, don't imply it's HTML, and don't insert a default Document Type Template
    $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_use_internal_errors(false); // restore xml parser error handlers
    return $dom;
  }

  /**
   * Wrapper around log method
   */
  private function debug_log() {
    if (self::DEBUG) {
      $args = func_get_args();
      $text = array_shift($args); // pop text off
      $this->log($text, $args);
    }
  }

  /**
   * Supports variable replacement of $text using sprintf
   *
   * @param string $text
   * @param
   *          unknown ...
   */
  private function log($text) {
    // Log to system, if available
    global $ost;

    if (func_num_args() > 1) {
      $args = func_get_args();
      array_shift($args);
      $text = sprintf($text, $args);
    }

    if (! $ost instanceof osTicket) {
      // doh, can't log to the admin log without this object
      // setup a callback to do the logging afterwards:
      if (! $this->messages) {
        register_shutdown_function(
          function () {
            $this->logAfter();
          });
      }
      // save the log message in memory for now
      // the callback registered above will retrieve it and log it
      $this->messages[] = $text;
      return;
    }

    error_log("AttachmentPreviewPlugin: $text");
    $ost->logInfo(wordwrap($text, 30), $text, FALSE);
  }

  /**
   * Calls Log function again before shutting down, allows logs to be logged in admin logs,
   * when they otherwise aren't able to be logged.
   * :-)
   */
  private function logAfter() {
    global $ost;
    if (! $ost instanceof osTicket) {
      error_log("Unable to log to admin log..");
      foreach ($this->messages as $text) {
        error_log("Emergency AttachmentPreviewPluginLog: $text");
      }
    }
    else {
      foreach ($this->messages as $text) {
        $this->log($text);
      }
    }
  }

  private function doRemoteWork($html) {
    // We haven't actually been asked to run our code here, but
    // as a service to other plugins, we'll make this API (? really?) available:
    $dom = $this->getDom($html);
    $dom = $this->processRemoteElements($dom);

    // Check for failure to generate HTML
    // DOMDocument::saveHTML() returns null on error
    $new_html = $dom->saveHTML();

    // just return the original if error
    return $new_html ?: $html;
  }

  /**
   * Provides an interface to safely inject HTML into any page.
   * Hopefully useful.
   *
   * Used like:
   *
   * AttachmentsPreviewPlugin::addRawHtml('<h2>Yo!</h2>','tag','body');
   *
   * Now, your <h2> will appear at the end of the <body> tag.
   *
   * AttachmentsPreviewPlugin::addRawHtml('<input type="button" value="Push Me" name="butt-on"/>','id','mark_overdue-confirm');
   *
   * Will add a pointless button into the confirmation form <p> that has html attribute id="mark_overdue-confirm"
   *
   * Can also use addRawHtml(<script> or <style> or whatever else you need.
   *
   * :-)
   *
   * @param string $html
   * @param string $locator
   *          one of: id,tag,xpath
   * @param string $expression
   *          an expression used by the locator to place the HTML Nodes within the Dom.
   */
  public static function addRawHtml($html = '', $locator = 'id',
    $expression = 'pjax-container') {
    $source_dom = new DOMDocument();
    $source_dom->loadHTML($html);

    if (! isset($source_dom->documentElement->childNodes)) {
      return;
    }
    foreach ($source_dom->documentElement->childNodes as $child) {
      self::$foreign_elements[] = (object) [
        'element' => $child,
        'locator' => $locator,
        'expression' => $expression
      ];
    }
  }

  /**
   * Prints whatever is given to it, after the page is done.
   *
   * Designed to occur AFTER bootstrapping.
   *
   * See end of checkPermissionsAndRun()
   *
   * @param string $html
   */
  public static function appendHtml($html) {
    $this->appended .= $html;
  }

  /**
   * Uses an Attached Image link and embeds the image into the DOM
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addIMG(DOMDocument $doc, DOMElement $link) {

    // Rebuild the download link as a normal clickable link, for full-size viewing:
    $a = $doc->createElement('a');
    $a->setAttribute('href', $link->getAttribute('href'));

    // Build an image of the referenced file, so we can simply preview it:
    $img = $doc->createElement('img');
    $img->setAttribute('src', $link->getAttribute('href'));
    $img->setAttribute('style', 'max-width: 100%');

    // Put the image inside the link, so the image is clickable (opens in new tab):
    $a->appendChild($img);

    // Add a title attribute to the download link:
    $link->setAttribute('title', 'Download this image.');

    $this->wrap($doc, $link, $a);
  }

  /**
   * Uses an Attached PDF link and embeds the linked document into the DOM
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addPDF(DOMDocument $doc, DOMElement $link) {
    $url = $link->getAttribute('href');
    // Build a Chrome/Firefox compatible <object> to hold the PDF
    $pdf = $doc->createElement('object');
    $pdf->setAttribute('width', '100%');
    $pdf->setAttribute('height', '1000px');
    // $pdf->setAttribute('data', $url . '&disposition=inline'); // Can't use inline disposition with XSS security rules.. :-(
    $pdf->setAttribute('type', 'application/pdf');
    $pdf->setAttribute('data-type', 'pdf');
    $pdf->setAttribute('data-url', $url);

    // Add a <b>Nope</b> type message for obsolete or text-based browsers.
    $message = $doc->createElement('b');
    $message->nodeValue = 'Your "browser" is unable to display this PDF. ';
    $call_to_action = $doc->createElement('a');
    $call_to_action->setAttribute('href', 'http://abetterbrowser.org/');
    $call_to_action->setAttribute('title',
      'Get a better browser to use this content inline.');
    $call_to_action->nodeValue = 'Help';
    $message->appendChild($call_to_action);
    $pdf->appendChild($message);

    // Build a backup <embed> to hide inside the <object> for low class browsers.. i.e: IE (we'll do it in js)
    $this->wrap($doc, $link, $pdf);
  }

  /**
   * Converts a link to Youtube player
   *
   * Fully loaded only, ie: <a src="youtube.com/v/12345">Link to youtube</a> only, not just a bare youtube URL.
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addYoutube(DOMDocument $doc, DOMElement $link) {
    $youtube_id = $this->getYoutubeIdFromUrl($link->getAttribute('href'));
    if ($youtube_id !== FALSE) {
      // Now we can add an iframe so the video is instantly playable.
      // eg: <iframe width="560" height="349" src="http://www.youtube.com/embed/something?rel=0&hd=1" frameborder="0" allowfullscreen></iframe>
      // TODO: Make responsive.. if required.
      $player = $doc->createElement('iframe');
      $player->setAttribute('width', '560');
      $player->setAttribute('height', '349');
      $player->setAttribute('src',
        'https://www.youtube.com/embed/' . $youtube_id . '?rel=0&hd=1');
      $player->setAttribute('frameborder', 0);
      $player->setAttribute('allowfullscreen', 1);
      $this->wrap($doc, $link, $player);
    }
  }

  /**
   * Converts a linked audio file into an embedded HTML5 player.
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addAudio(DOMDocument $doc, DOMElement $link) {
    $audio = $doc->createElement('audio');
    // $audio->setAttribute('autoplay','false'); //TODO: See if anyone wants these as admin options
    // $audio->setAttribute('loop','false');
    $audio->setAttribute('preload', 'auto');
    $audio->setAttribute('controls', 1);
    $audio->setAttribute('src', $link->getAttribute('href'));
    $this->wrap($doc, $link, $audio);
  }

  /**
   * Converts a linked video file into an embedded HTML5 player.
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addVideo(DOMDocument $doc, DOMElement $link) {
    $video = $doc->createElement('video');
    $video->setAttribute('controls', 1);
    $source = $doc->createElement('source');
    $source->setAttribute('src', $link->getAttribute('href'));
    $source->setAttribute('type',
      'video/' . $this->getExtension($link->textContent));
    $video->appendChild($source);
    $this->wrap($doc, $link, $video);
  }

  /**
   * Fetches an HTML attachment as the user via the browser, and injects it into the DOM.
   * Attempts have been made to sanitize it a bit.
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addHTML(DOMDocument $doc, DOMElement $link) {

    // The files were getting complex to parse manually.. and need to be downloaded by the browser to display anyway,
    // let's just use a wee script to pull them as the user?
    // It's a bit messy, but the first script is only included once.. and it is used to prevent some XSS type attacks..
    // wouldn't want an html attachment to break everything..
    static $trim_func;
    if (! $trim_func) {
      $trim_func = TRUE;
      $t = $doc->createElement('script');
      $t->setAttribute('name', 'HTML Sanitizer');
      $t->nodeValue = file_get_contents('sanitizer.js');
      $doc->appendChild($t);
    }

    $d = $doc->createElement('div');
    $d->setAttribute('data-url', $link->getAttribute('href'));
    $d->setAttribute('data-type', 'html');
    $this->wrap($doc, $link, $d);
  }

  /**
   * Fetches a TEXT attachment entirely, and injects it into the DOM via ajax
   *
   * @param DOMDocument $doc
   * @param DOMElement $link
   */
  private function addTEXT(DOMDocument $doc, DOMElement $link) {
    $pre = $doc->createElement('pre');
    $pre->setAttribute('data-url', $link->getAttribute('href'));
    $pre->setAttribute('data-type', 'text');
    $this->wrap($doc, $link, $pre);
  }

  /**
   * Convenience function
   * Just wanted a DOMElement without having to copy & paste..
   * or repetitively appendChild..
   * Constructs a <div> element to contain the new inlined attachment.
   *
   * @todo : Move stylesheet into global
   * @param DOMDocument $doc
   * @param DOMElement $source
   * @param DOMElement $new_child
   */
  private function wrap(DOMDocument $doc, DOMElement $source,
    DOMElement $new_child) {
    // Implement a limit for attachments. Only show the admin configured amount at first
    // if there are any more, we will inject them, however they will be shown as buttons
    static $number;

    if (! isset($number)) {

      // We can use this static check to insert the css once as well!
      // Build an attachments stylesheet for everything that get's wrapped (everything)
      $css = $doc->createElement('style');
      $css->setAttribute('name', 'Attachments Preview Stylesheet');
      $css->nodeValue = file_get_contents('stylesheet.css');
      $source->parentNode->appendChild($css);

      // This script simply toggles the display of the attachment
      $toggle_script = $doc->createElement('script');
      $toggle_script->setAttribute('type', 'text/javascript');
      $toggle_script->setAttribute('name', 'Attachments Preview Toggle Script');

      // I'm against dynamically generated scripts, however in this case
      // it makes it translateable.. so, win!
      $replace = [
        '#SHOW#' => __('Show Attachment'),
        '#HIDE#' => __('Hide Attachment'),
        '#LIMIT#' => $this->limit
      ];
      $toggle_script->nodeValue = str_replace(array_keys($replace),
        array_values($replace), file_get_contents('script.js'));
      $source->parentNode->appendChild($toggle_script);
    }

    // Build a wrapper element to contain the attachment
    $wrapper = $doc->createElement('div');
    $number++; // Which attachment are we adding? Let's give it a number.
    $id = 'ap-file-' . $number;
    $wrapper->setAttribute('id', $id);

    // Brand the child with the parent's id.. for ease of scripting
    $cid = "$id-c";
    $new_child->setAttribute('id', $cid);

    // Add the wrapped embedded attachment into the wrapper:
    $wrapper->appendChild($new_child);

    // See if we are over the admin-defined maximum number of inline-attachments:
    if ($this->limit && $number > $this->limit) {
      // Instead of injecting the element normally, let's instead hide it, and show a button to click
      $button = $doc->createElement('a');
      $button->setAttribute('class', 'button'); // Sexify the "button" with buttony goodness!
      $button->setAttribute('onClick', "javascript:ap_toggle(this,'$id');");
      $button->nodeValue = __('Show Attachment'); // Initially set the text to this
      $wrapper->setAttribute('class', 'embedded hidden hidden-attachment'); // Set the class of the wrapper to hidden-attachment
      $source->parentNode->appendChild($button); // Insert the button before the wrapper, so it stays where it is when the wrapper expands.
    }
    else {
      // This attachment isn't limited, so, don't hide it:
      $wrapper->setAttribute('class', 'embedded');
    }
    // Add the wrapper to the thread.
    $source->parentNode->appendChild($wrapper);
  }

  /**
   * Get Youtube video ID from URL
   *
   * http://stackoverflow.com/a/9785191
   *
   * Looks painful, haven't tested the performance impact, however Admin can disable checking.
   *
   * @param string $url
   * @return mixed Youtube video ID or FALSE if not found
   */
  private function getYoutubeIdFromUrl($url) {
    // Series of possible url patterns, please pull-request any others you find!
    // Ideally they are in "most-common" first order.
    $regex = array(
      '/youtube\.com\/watch\?v=([^\&\?\/]+)/',
      '/youtube\.com\/embed\/([^\&\?\/]+)/',
      '/youtube\.com\/v\/([^\&\?\/]+)/',
      '/youtu\.be\/([^\&\?\/]+)/',
      '/youtube\.com\/verify_age\?next_url=\/watch%3Fv%3D([^\&\?\/]+)/'
    );
    $match = array();
    foreach ($regex as $pattern) {
      if (preg_match($pattern, $url, $match)) {
        return $match[1]; // Return the matched video ID
      }
    }
    // not a youtube video
    return false;
  }

  /**
   * Receives a DOMDocument, returns a DOMDocument that might contain foreign element changes
   *
   * @param DOMDocument $dom
   * @throws \Exception
   * @return DOMDocument
   */
  private function processRemoteElements(DOMDocument &$dom) {
    // $this->foreign_elements should be an array of structures like:
    /**
     * array('sourceClassName' => array(
     * (object)[
     * 'element' => $element, // The DOMElement to replace/inject etc.
     * 'locator' => 'tag', // EG: tag/id/xpath
     * 'replace_found' => FALSE, // default
     * 'setAttribute' => array('attribute_name' => 'attribute_value'), // Not included by default, but great for adding tiny customizations.
     * 'expression' => 'body' // which tag/id/xpath etc.
     * ],
     * (object2)[], etc.
     * ));
     */
    foreach (self::$foreign_elements as $source => $structures) {
      $this->debug_log("Loading %d remote structures from %s",
        count($structures), $source);
      foreach ($structures as $structure) {
        // Validate the Structure
        try {
          if (! is_object($structure)) {
            continue; // just skip this fail.
          }

          if (! property_exists($structure, 'setAttribute') && (! property_exists(
            $structure, 'element') || ! is_object($structure->element) ||
             ! $structure->element instanceof DOMElement)) {
            // What?
            throw new \Exception(
              "Invalid or missing parameter 'element' from source {$source}.");
          }

          // Verify that the sender used a tag/id/xpath
          if (! property_exists($structure, 'locator')) {
            throw new \Exception("Invalid or missing locator");
          }
          if (! property_exists($structure, 'replace_found')) {
            $structure->replace_found = FALSE;
          }

          if (! property_exists($structure, 'expression')) {
            throw new \Exception("Invalid or missing expression");
          }

          // Load the element(s) into our DOM, we can't insert them until then.
          if (! property_exists($structure, 'setAttribute')) {
            // we aren't just changing an attribute, we are inserting new or replacing.
            $imported_element = $dom->importNode($structure->element, true);
          }

          // Based on type of DOM Selector, lets insert this imported element.
          switch ($structure->locator) {
            case 'xpath':
              // TODO: Fix this.. doesn't seem to work
              $finder = new \DOMXPath($dom);
              $test = 0;
              foreach ($finder->query($structure->expression) as $node) {
                $test++;
                $this->updateStructure($node, $structure, $imported_element);
              }
              if (! $test) {
                throw new Exception("Nothing matched: {$structure->expression}");
              }
              break;
            case 'id':
              // Note, ID doesn't mean jQuery $('#id'); usefulness.. its xml:id="something", which none of our docs will have.
              $finder = new \DOMXPath($dom);
              $node = $finder->query("//*[@id='{$structure->expression}']")->item(
                0);
              if (! $node) {
                $this->log("Unable to find node with expression %s",
                  $structure->expression);
                continue;
              }
              $this->updateStructure($node, $structure, $imported_element);
              break;
            case 'tag':
              foreach ($dom->getElementsByTagName($structure->expression) as $node) {
                $this->updateStructure($node, $structure, $imported_element);
              }
              break;
            default:
              $this->log(
                "Your locator from %s is invalid, %s has not been implemented. Available options are: xpath,id,tag",
                $source, $structure->locator);
              continue;
          }
        } catch (\Exception $de) {
          $this->log("%s triggered DOM error: %s", $source, $de->getMessage());
        }
      }
    }
    // Clear the array
    self::$foreign_elements = array();
  }

  /**
   * Connects a remote structure with a DOMElement.
   * either setting attributes, or appending or replacing nodes..
   *
   * @param \DOMElement $node
   * @param stdClass $structure
   * @param \DOMElement $imported_element
   */
  private function updateStructure(\DOMElement $node, $structure,
    \DOMElement $imported_element = null) {
    if ($structure->replace_found) {
      $node->parentNode->replaceChild($node, $imported_element);
    }
    elseif ($structure->setAttribute) {
      foreach ($structure->setAttribute as $key => $val) {
        $node->setAttribute($key, $val);
      }
    }
    else {
      $node->appendChild($imported_element);
    }
  }

  /**
   * Retrieve the file extension from a string in lowercase
   *
   * @param DOMElement $link
   * @return string
   */
  public static function getExtension($string) {
    return trim(strtolower(pathinfo($string, PATHINFO_EXTENSION)));
  }

  /**
   * We only want to inject when viewing tickets, not when EDITING tickets..
   * or any other view.
   *
   * Available statically via: AttachmentPreviewPlugin::isTicketsView()
   *
   * @return bool whether or not current page is viewing a ticket.
   */
  public static function isTicketsView() {
    // This ensures no matter how many plugins call this function, it only checks it once.
    static $tickets_view;
    $url = $_SERVER['REQUEST_URI']; // convenience for below

    // Only set the $tickets_view if we've not set it (Will not last beyond each page anyway, but you never know, I call this from my plugins too)
    if (! isset($tickets_view)) {
      // Ignore POST data, unless we're seeing a new ticket, then don't ignore.
      if (isset($_POST['a']) && $_POST['a'] == 'open') {
        $tickets_view = TRUE;
      }
      elseif (strpos($url, '/scp/') === FALSE) {
        // URL doesn't include /scp/ so isn't an admin page
        $tickets_view = FALSE;
      }
      elseif (isset($_POST) && count($_POST)) {
        // If something has been POST'd to osTicket, we don't want any part of that.
        // We're obviously not just "Viewing" a ticket if we've posted something, you only post to change!
        // Resolves issue #3 regarding printing of tickets.
        // EXCEPT for new tickets.. because of reasons.
        $tickets_view = FALSE;
      }
      elseif (strpos($url, 'a=edit')) {
        // URL contains a=edit, which we don't want to change yet
        $tickets_view = FALSE;
      }
      elseif (strpos($url, 'index.php') !== FALSE ||
         strpos($url, 'tickets.php') !== FALSE) {
        // URL contains either index.php or tickets.php, so just might be a ticket page..
        $tickets_view = TRUE;
      }

      if (self::DEBUG)
        error_log(
          "Matched $url as " . ($tickets_view ? 'ticket' : 'not ticket'));
    }
    return $tickets_view;
  }

  /**
   * Determines if the page was/is being build from a PJAX request.
   * Uses the sneaky cheat request header method..
   *
   * @return bool
   */
  public static function isPjax() {
    return (isset($_SERVER['HTTP_X_PJAX']) && $_SERVER['HTTP_X_PJAX'] == 'true');
  }

  /**
   * Required stub.
   *
   * {@inheritdoc}
   *
   * @see Plugin::uninstall()
   */
  function uninstall() {
    $errors = [];
    parent::uninstall($errors);
  }
}

