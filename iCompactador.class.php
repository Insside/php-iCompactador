<?php

if (!isset($root)) {
  $root = '../../';
}
if (!class_exists('iAnalisis')) {
  require_once($root . "librerias/iCompactador/iAnalisis.class.php");
}

/**
 * 
 */
class iCompactador {
  /**
   * $analizadores: listado de funciones de análisis, que van a ser ejecutadas.
   */

  /** Constantes & Parametros * */
  const ignorar = '$1';

  private $codigo = '';
  private $codificacion = 62;
  private $rapida = true;
  private $especiales = false;
  private $codificaciones = array('None' => 0, 'Numeric' => 10, 'Normal' => 62, 'High ASCII' => 95);
  private $analizadores = array();
  private $conteo = array();
  private $regulador;

  public function __construct($codigo, $codificacion = 62, $rapida = true, $especiales = false) {
    $this->codigo = $codigo . "\n";
    if (array_key_exists($codificacion, $this->codificaciones)) {
      $codificacion = $this->codificaciones[$codificacion];
    }
    $this->codificacion = min((int) $codificacion, 95);
    $this->rapida = $rapida;
    $this->especiales = $especiales;
  }

  public function Compactar() {
    $this->set_Analizador('Compresion');
    if ($this->especiales) {
      $this->set_Analizador('_encodeSpecialChars');
    }
    if ($this->codificacion) {
      $this->set_Analizador('_encodeKeywords');
    }
    return($this->Analisis($this->codigo));
  }

  private function set_Analizador($analizador) {
    $this->analizadores[] = $analizador;
  }

  /**
   * Esta clase aplica todas las rutinas de analisis gramatical al codigo proporsionado
   * @param type $script
   * @return type
   */
  private function Analisis($codigo) {
    for ($i = 0; isset($this->analizadores[$i]); $i++) {
      $codigo = call_user_func(array(&$this, $this->analizadores[$i]), $codigo);
    }
    return($codigo);
  }

  /**
   * Analiza todas las expresiones y palabras en el código.
   * $_sorted: Lista de palabras ordenadas según la frecuencia.
   * $_encoded: Diccionario de palabra-> Codificación
   * $_protected: Instancias de palabras "protegidas
   * $all: Simula el JavaScript total: 
   * $unsorted: Misma lista, Sin ordenar
   * $value: Diccionario de codigos de caracteres (eg. 256->ff)
   * @param type $script
   * @param type $regexp
   * @param type $encode
   * @return type
   */
  private function Analizar($script, $regexp, $encode) {
    $all = array();
    preg_match_all($regexp, $script, $all);
    $_sorted = array();
    $_encoded = array();
    $_protected = array();
    $all = $all[0];
    if (!empty($all)) {
      $unsorted = array();
      $protected = array();
      $value = array();
      $this->conteo = array();
      $i = count($all);
      $j = 0;
      do {
        --$i;
        $word = '$' . $all[$i];
        if (!isset($this->conteo[$word])) {
          $this->conteo[$word] = 0;
          $unsorted[$j] = $word;
          $values[$j] = call_user_func(array(&$this, $encode), $j);
          $protected['$' . $values[$j]] = $j++;
        }
        $this->conteo[$word] ++;
      } while ($i > 0);
      $i = count($unsorted);
      do {
        $word = $unsorted[--$i];
        if (isset($protected[$word]) /* != null */) {
          $_sorted[$protected[$word]] = substr($word, 1);
          $_protected[$protected[$word]] = true;
          $this->conteo[$word] = 0;
        }
      } while ($i);
      usort($unsorted, array(&$this, '_sortWords'));
      $j = 0;
      do {
        if (!isset($_sorted[$i]))
          $_sorted[$i] = substr($unsorted[$j++], 1);
        $_encoded[$_sorted[$i]] = $values[$i];
      } while (++$i < count($unsorted));
    }
    return(array('sorted' => $_sorted, 'encoded' => $_encoded, 'protected' => $_protected));
  }

  /**
   * Este metodo aplica los patrones de analisis iniciales eliminando espacios en blanco y comentarios,
   * presentes en el código fuente ingresado.
   * @param type $codigo: Corresponde al codigo fuente original ingresado en formato JavaScript.
   * @return type String: Retorna el codigo fuente comprimido.
   */
  private function Compresion($codigo) {
    $analizador = new iAnalisis();
    $analizador->escapeChar = '\\';
    $analizador->add('/\'[^\'\\n\\r]*\'/', self::ignorar); // Protege las cadenas
    $analizador->add('/"[^"\\n\\r]*"/', self::ignorar); // Protege las cadenas
    $analizador->add('/\\/\\/[^\\n\\r]*[\\n\\r]/', ' ');    // Remueve los comentarios
    $analizador->add('/\\/\\*[^*]*\\*+([^\\/][^*]*\\*+)*\\//', ' '); // Protege las expresiones regulares
    $analizador->add('/\\s+(\\/[^\\/\\n\\r\\*][^\\/\\n\\r]*\\/g?i?)/', '$2'); // ignorar
    $analizador->add('/[^\\w\\x24\\/\'"*)\\?:]\\/[^\\/\\n\\r\\*][^\\/\\n\\r]*\\/g?i?/', self::ignorar); // remove: ;;; doSomething();
    if ($this->especiales) {
      $analizador->add('/;;;[^\\n\\r]+[\\n\\r]/');
    }// Remueve los punto y coma redundantes.
    $analizador->add('/\\(;;\\)/', self::ignorar); // Protege las repeticiones en los for (;;) 
    $analizador->add('/;+\\s*([};])/', '$2');
    $analisis['primario'] = $analizador->exec($codigo); //Aplica todo lo anterior
    $analizador->add('/(\\b|\\x24)\\s+(\\b|\\x24)/', '$2 $3'); // remove white-space
    $analizador->add('/([+\\-])\\s+([+\\-])/', '$2 $3');
    $analizador->add('/\\s+/', '');
    $analisis['secundario'] = $analizador->exec($analisis['primario']);
    return($analisis['secundario']);
  }

  private function _encodeSpecialChars($script) {
    $analizador = new iAnalisis();
    // replace: $name -> n, $$name -> na
    $analizador->add('/((\\x24+)([a-zA-Z$_]+))(\\d*)/', array('fn' => '_replace_name')
    );
    // replace: _name -> _0, double-underscore (__name) is ignored
    $regexp = '/\\b_[A-Za-z\\d]\\w*/';
    // build the word list
    $keywords = $this->Analizar($script, $regexp, '_encodePrivate');
    // quick ref
    $encoded = $keywords['encoded'];

    $analizador->add($regexp, array(
        'fn' => '_replace_encoded',
        'data' => $encoded
            )
    );
    return $analizador->exec($script);
  }

  private function _encodeKeywords($script) {
    // escape high-ascii values already in the script (i.e. in strings)
    if ($this->codificacion > 62)
      $script = $this->_escape95($script);
    // create the analizador
    $analizador = new iAnalisis();
    $encode = $this->_getEncoder($this->codificacion);
    // for high-ascii, don't encode single character low-ascii
    $regexp = ($this->codificacion > 62) ? '/\\w\\w+/' : '/\\w+/';
    // build the word list
    $keywords = $this->Analizar($script, $regexp, $encode);
    $encoded = $keywords['encoded'];

    // encode
    $analizador->add($regexp, array(
        'fn' => '_replace_encoded',
        'data' => $encoded
            )
    );
    if (empty($script))
      return $script;
    else {
      //$res = $analizador->exec($script);
      //$res = $this->_bootStrap($res, $keywords);
      //return $res;
      return $this->_bootStrap($analizador->exec($script), $keywords);
    }
  }

  private function _sortWords($match1, $match2) {
    return $this->conteo[$match2] - $this->conteo[$match1];
  }

  // build the boot function used for loading and decoding
  private function _bootStrap($packed, $keywords) {
    $ENCODE = $this->_safeRegExp('$encode\\($count\\)');

    // $packed: the packed script
    $packed = "'" . $this->_escape($packed) . "'";

    // $ascii: base for encoding
    $ascii = min(count($keywords['sorted']), $this->codificacion);
    if ($ascii == 0)
      $ascii = 1;

    // $count: number of words contained in the script
    $count = count($keywords['sorted']);

    // $keywords: list of words contained in the script
    foreach ($keywords['protected'] as $i => $value) {
      $keywords['sorted'][$i] = '';
    }
    // convert from a string to an array
    ksort($keywords['sorted']);
    $keywords = "'" . implode('|', $keywords['sorted']) . "'.split('|')";

    $encode = ($this->codificacion > 62) ? '_encode95' : $this->_getEncoder($ascii);
    $encode = $this->_getJSFunction($encode);
    $encode = preg_replace('/codificacion/', '$ascii', $encode);
    $encode = preg_replace('/arguments\\.callee/', '$encode', $encode);
    $inline = '\\$count' . ($ascii > 10 ? '.toString(\\$ascii)' : '');

    // $decode: code snippet to speed up decoding
    if ($this->rapida) {
      // create the decoder
      $decode = $this->_getJSFunction('_decodeBody');
      if ($this->codificacion > 62)
        $decode = preg_replace('/\\\\w/', '[\\xa1-\\xff]', $decode);
      // perform the encoding inline for lower ascii values
      elseif ($ascii < 36)
        $decode = preg_replace($ENCODE, $inline, $decode);
      // special case: when $count==0 there are no keywords. I want to keep
      //  the basic shape of the unpacking funcion so i'll frig the code...
      if ($count == 0)
        $decode = preg_replace($this->_safeRegExp('($count)\\s*=\\s*1'), '$1=0', $decode, 1);
    }

    // boot function
    $unpack = $this->_getJSFunction('_unpack');
    if ($this->rapida) {
      // insert the decoder
      $this->regulador = $decode;
      $unpack = preg_replace_callback('/\\{/', array(&$this, '_insertFastDecode'), $unpack, 1);
    }
    $unpack = preg_replace('/"/', "'", $unpack);
    if ($this->codificacion > 62) { // high-ascii
      // get rid of the word-boundaries for regexp matches
      $unpack = preg_replace('/\'\\\\\\\\b\'\s*\\+|\\+\s*\'\\\\\\\\b\'/', '', $unpack);
    }
    if ($ascii > 36 || $this->codificacion > 62 || $this->rapida) {
      // insert the encode function
      $this->regulador = $encode;
      $unpack = preg_replace_callback('/\\{/', array(&$this, '_insertFastEncode'), $unpack, 1);
    } else {
      // perform the encoding inline
      $unpack = preg_replace($ENCODE, $inline, $unpack);
    }
    // pack the boot function too
    $unpackPacker = new iCompactador($unpack, 0, false, true);
    $unpack = $unpackPacker->Compactar();

    // arguments
    $params = array($packed, $ascii, $count, $keywords);
    if ($this->rapida) {
      $params[] = 0;
      $params[] = '{}';
    }
    $params = implode(',', $params);

    // the whole thing
    return 'eval(' . $unpack . '(' . $params . "))\n";
  }

  private function _insertFastDecode($match) {
    return '{' . $this->regulador . ';';
  }

  private function _insertFastEncode($match) {
    return '{$encode=' . $this->regulador . ';';
  }

  // mmm.. ..which one do i need ??
  private function _getEncoder($ascii) {
    return $ascii > 10 ? $ascii > 36 ? $ascii > 62 ? '_encode95' : '_encode62' : '_encode36' : '_encode10';
  }

  // zero encoding
  // characters: 0123456789
  private function _encode10($charCode) {
    return $charCode;
  }

  // inherent base36 support
  // characters: 0123456789abcdefghijklmnopqrstuvwxyz
  private function _encode36($charCode) {
    return base_convert($charCode, 10, 36);
  }

  // hitch a ride on base36 and add the upper case alpha characters
  // characters: 0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ
  private function _encode62($charCode) {
    $res = '';
    if ($charCode >= $this->codificacion) {
      $res = $this->_encode62((int) ($charCode / $this->codificacion));
    }
    $charCode = $charCode % $this->codificacion;

    if ($charCode > 35)
      return $res . chr($charCode + 29);
    else
      return $res . base_convert($charCode, 10, 36);
  }

  // use high-ascii values
  // characters: ¡¢£¤¥¦§¨©ª«¬­®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþ
  private function _encode95($charCode) {
    $res = '';
    if ($charCode >= $this->codificacion)
      $res = $this->_encode95($charCode / $this->codificacion);

    return $res . chr(($charCode % $this->codificacion) + 161);
  }

  private function _safeRegExp($string) {
    return '/' . preg_replace('/\$/', '\\\$', $string) . '/';
  }

  private function _encodePrivate($charCode) {
    return "_" . $charCode;
  }

  // protect characters used by the analizador
  private function _escape($script) {
    return preg_replace('/([\\\\\'])/', '\\\$1', $script);
  }

  // protect high-ascii characters already in the script
  private function _escape95($script) {
    return preg_replace_callback(
            '/[\\xa1-\\xff]/', array(&$this, '_escape95Bis'), $script
    );
  }

  private function _escape95Bis($match) {
    return '\x' . ((string) dechex(ord($match)));
  }

  private function _getJSFunction($aName) {
    if (defined('self::JSFUNCTION' . $aName))
      return constant('self::JSFUNCTION' . $aName);
    else
      return '';
  }

  // JavaScript Functions used.
  // Note : In Dean's version, these functions are converted
  // with 'String(aFunctionName);'.
  // This internal conversion complete the original code, ex :
  // 'while (aBool) anAction();' is converted to
  // 'while (aBool) { anAction(); }'.
  // The JavaScript functions below are corrected.
  // unpacking function - this is the boot strap function
  //  data extracted from this packing routine is passed to
  //  this function when decoded in the target
  // NOTE ! : without the ';' final.
  const JSFUNCTION_unpack = 'function($packed, $ascii, $count, $keywords, $encode, $decode) {
    while ($count--) {
        if ($keywords[$count]) {
            $packed = $packed.replace(new RegExp(\'\\\\b\' + $encode($count) + \'\\\\b\', \'g\'), $keywords[$count]);
        }
    }
    return $packed;
}';
  /*
    'function($packed, $ascii, $count, $keywords, $encode, $decode) {
    while ($count--)
    if ($keywords[$count])
    $packed = $packed.replace(new RegExp(\'\\\\b\' + $encode($count) + \'\\\\b\', \'g\'), $keywords[$count]);
    return $packed;
    }';
   */
  // code-snippet inserted into the unpacker to speed up decoding
  const JSFUNCTION_decodeBody = //_decode = function() {
// does the browser support String.replace where the
//  replacement value is a function?
          '    if (!\'\'.replace(/^/, String)) {
        // decode all the values we need
        while ($count--) {
            $decode[$encode($count)] = $keywords[$count] || $encode($count);
        }
        // global replacement function
        $keywords = [function ($encoded) {return $decode[$encoded]}];
        // generic match
        $encode = function () {return \'\\\\w+\'};
        // reset the loop counter -  we are now doing a global replace
        $count = 1;
    }
';
//};
  /*
    '	if (!\'\'.replace(/^/, String)) {
    // decode all the values we need
    while ($count--) $decode[$encode($count)] = $keywords[$count] || $encode($count);
    // global replacement function
    $keywords = [function ($encoded) {return $decode[$encoded]}];
    // generic match
    $encode = function () {return\'\\\\w+\'};
    // reset the loop counter -  we are now doing a global replace
    $count = 1;
    }';
   */

  // zero encoding
  // characters: 0123456789
  const JSFUNCTION_encode10 = 'function($charCode) {
    return $charCode;
}'; //;';
  // inherent base36 support
  // characters: 0123456789abcdefghijklmnopqrstuvwxyz
  const JSFUNCTION_encode36 = 'function($charCode) {
    return $charCode.toString(36);
}'; //;';
  // hitch a ride on base36 and add the upper case alpha characters
  // characters: 0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ
  const JSFUNCTION_encode62 = 'function($charCode) {
    return ($charCode < codificacion ? \'\' : arguments.callee(parseInt($charCode / codificacion))) +
    (($charCode = $charCode % codificacion) > 35 ? String.fromCharCode($charCode + 29) : $charCode.toString(36));
}';
  // use high-ascii values
  // characters: ¡¢£¤¥¦§¨©ª«¬­®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþ
  const JSFUNCTION_encode95 = 'function($charCode) {
    return ($charCode < codificacion ? \'\' : arguments.callee($charCode / codificacion)) +
        String.fromCharCode($charCode % codificacion + 161);
}';

}

?>