<?php

class EDContentExtractor {

  // Results
  public $elements = [];

  // Content source
  public $post = null;
  public $blocks = null;

  // Configuration
  public $limit = -1;
  public $allowedTags = [];
  public $decodeEntities = true;
  public $fixSentences = true;
  public $useInlineText = true;

  // Block processing rules
  public $innerContentBlocks = ["core/paragraph", "core/list-item", "core/heading"];

  public function __construct($args = [
    'post' => null,
    'limit' => -1,
    'allowedTags' => [],
    'blocks' => null,
    'decodeEntities' => true,
    'fixSentences' => true,
    'useInlineText' => true
  ]) {
    if (isset($args['post'])) {
      $this->post = $args['post'];
    }
    if (isset($args['limit'])) {
      $this->limit = $args['limit'];
    }
    if (isset($args['allowedTags'])) {
      $this->allowedTags = $args['allowedTags'];
    }
    if (isset($args['decodeEntities'])) {
      $this->decodeEntities = $args['decodeEntities'];
    }
    if (isset($args['fixSentences'])) {
      $this->fixSentences = $args['fixSentences'];
    }
    if (isset($args['useInlineText'])) {
      $this->useInlineText = $args['useInlineText'];
    }
    if (isset($args['blocks'])) {
      $this->blocks = $args['blocks'];
    } else if ($this->post) {
      $this->blocks = parse_blocks($this->post->post_content);
    }
  }

  public function addBlocks($blocks) {
    foreach ($blocks as $block) {
      $this->addBlock($block);
    }
  }

  public function addText($text) {
    if ($this->allowedTags !== 'all') {
      $text = strip_tags($text, $this->allowedTags);
    }
    if ($this->decodeEntities) {
      $text = html_entity_decode($text);
    }
    $text = htmlspecialchars_decode($text);
    $text = trim($text);
    if ($this->fixSentences) {
      $lastCharacter = substr($text, -1);
      if (preg_match('/[a-zA-Z0-9]/', $lastCharacter)) {
        $text .= '.';
      }
    }
    if (!$text) return;
    $this->elements[] = [
      'text' => $text
    ];
  }

  public function addBlock($block) {
    if (in_array($block['blockName'], $this->innerContentBlocks)) {
      $this->addText($block['innerHTML']);
    }
    if ($this->useInlineText) {
      if (isset($block['attrs']) && isset($block['attrs']['inline']) && is_array($block['attrs']['inline'])) {
        foreach ($block['attrs']['inline'] as $value) {
          if (is_string($value)) {
            $this->addText($value);
          } else {
            // TDODO: Handle non-string inline values?
          }
        }
      }
    }
    foreach ($block['innerBlocks'] as $innerBlock) {
      $this->addBlock($innerBlock);
    }
  }

  public function process() {
    $this->elements = [];
    if (is_array($this->blocks)) {
      $this->addBlocks($this->blocks);
    }
  }

  public function getText() {
    $result = [];
    foreach ($this->elements as $element) {
      $result[] = $element['text'];
    }
    return implode("\n", $result);
  }

  public function getExcerpt() {
    $result = "";
    $maxLength = 150;
    foreach ($this->elements as $i => $element) {
      if ($i !== 0) $result .= ' ';
      $result .= $element['text'];
      if (strlen($result) > $maxLength) break;
    }
    if (strlen($result) > $maxLength) {
      $result = substr($result, 0, $maxLength);
      $result = substr($result, 0, strrpos($result, ' '));
      $result .= '...';
    }
    return $result;
  }
}
