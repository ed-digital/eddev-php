<?

namespace ED;

class Boot {
  static function boot() {
    require_once(__dir__."/../core/ed.php");
    ED()->getInstance();
  }
}