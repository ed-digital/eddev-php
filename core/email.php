<?php

class EDEmailMessage {

  public $recipients = [];
  public $baseTemplate = '';
  public $messageText = '';
  public $subject = '';
  public $messageReplacements = [];

  public function __construct() {
    $this->baseTemplate = ED()->themePath . "/backend/email/email-base.php";
  }

  public function setBaseTemplate($template) {
    $this->baseTemplate = $template;
    return $this;
  }

  public function addRecipient($email, $name = "") {
    $addresses = preg_split("/\s*\,\s*/", $email);
    foreach ($addresses as $email) {
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->recipients[] = (object)[
          'email' => $email,
          'name' => $name
        ];
      }
    }
    return $this;
  }

  public function setSubject($subject) {
    $this->subject = $subject;
    return $this;
  }

  public function setMessage($message, $replacements = []) {
    $this->messageText = $message;
    $this->messageReplacements = $replacements;
    return $this;
  }

  public function getMessage($recipient) {
    return $this->replaceText($this->messageText, $recipient);
  }

  public function setReplyTo($address) {
    $this->replyTo = $address;
  }

  private function replaceText($message, $recipient) {
    return preg_replace_callback("/{([a-z0-9\.\-\_]+)}/i", function ($item) use ($recipient, $message) {
      $key = $item[1];
      if ($key === 'recipientName') return $recipient->name;
      if ($key === 'recipientEmail') return $recipient->email;
      if ($key === 'subject') return $this->subject === $message ? $this->subject : $this->replaceText($this->subject, $recipient);
      $replacement = @$this->messageReplacements[$key];
      return $replacement ?? '';
    }, $message);
  }

  private function wrapMessage($message, $recipient) {
    if (!file_exists($this->baseTemplate)) {
      throw new Error("Could not locate base email template.");
    }

    ob_start();
    include($this->baseTemplate);
    $contents = ob_get_contents();
    ob_end_clean();

    return $this->replaceText($contents, $recipient);
  }

  public function send($debug = false) {
    foreach ($this->recipients as $recipient) {
      $toAddress = $recipient->name
        ? preg_replace("/[^A-Z0-9\s]/i", "", $recipient->name) . " <" . $recipient->email . ">"
        : $recipient->email;

      $subject = $this->replaceText($this->subject, $recipient);

      $message = $this->getMessage($recipient);
      $message = $this->wrapMessage($message, $recipient);

      $headers = [
        'Content-Type: text/html; charset=UTF-8'
      ];

      if ($this->replyTo) {
        $headers[] = 'Reply-To: ' . $this->replyTo;
      }

      if ($debug) {
        echo $message;
      } else {
        wp_mail($toAddress, $subject, $message, $headers);
      }
    }
    return $this;
  }

  public function debug() {
    return $this->send(true);
  }
}
