<?php

namespace TypesenseIndex\Helper;

class Log
{
  private static $heading = "Typesense Index: ";

  /**
   * Write error
   *
   * @return void
   */
  public static function error($message)
  {
    error_log(self::$heading . $message);
  }
}
