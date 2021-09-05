<?php

  class EDCore {
    function __construct($config) {
      echo 123;
    }
  }

  function ED() {
    return new EDCore();
  }

?>