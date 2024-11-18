<?php

namespace ED;

// Function which allows us to test if the user is logged in, before the init action
function early_user_logged_in() {
  return isset($_COOKIE[LOGGED_IN_COOKIE]) && $_COOKIE[LOGGED_IN_COOKIE] ? true : false;
}
