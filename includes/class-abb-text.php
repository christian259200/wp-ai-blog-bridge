<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UTF-8 string helpers that stay correct without the mbstring extension.
 *
 * This matters for Spanish copy: falling back to strlen() counts "serigrafía"
 * as 11 bytes instead of 10 characters, which is enough to push a title over
 * an audit limit it never actually crossed. PCRE's /u modifier is always
 * available, so it carries the fallback.
 */
class ABB_Text {

	public static function length( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $text );

		return false === $count ? strlen( $text ) : $count;
	}

	public static function lower( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtr(
			strtolower( $text ),
			array(
				'Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú',
				'Ü' => 'ü', 'Ñ' => 'ñ', 'À' => 'à', 'È' => 'è', 'Ì' => 'ì',
				'Ò' => 'ò', 'Ù' => 'ù', 'Ç' => 'ç', 'Ä' => 'ä', 'Ë' => 'ë',
				'Ï' => 'ï', 'Ö' => 'ö', 'Â' => 'â', 'Ê' => 'ê', 'Î' => 'î',
				'Ô' => 'ô', 'Û' => 'û', 'Ã' => 'ã', 'Õ' => 'õ',
			)
		);
	}

	public static function substr( $text, $start, $length ) {
		$text = (string) $text;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}

		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $characters ) ) {
			return substr( $text, $start, $length );
		}

		return implode( '', array_slice( $characters, $start, $length ) );
	}

	/**
	 * Section labels in the site's language.
	 *
	 * The plugin ships no translation files, and an English "Key takeaways"
	 * heading on a Spanish blog is a visible defect, so the handful of strings
	 * the plugin injects into published content are resolved from the locale.
	 * An explicit label in the payload always wins over these.
	 */
	public static function label( $key ) {
		$labels = array(
			'en' => array(
				'by'            => 'By',
				'key_takeaways' => 'Key takeaways',
				'toc'           => 'On this page',
				'faq'           => 'Frequently asked questions',
				'sources'       => 'Sources',
				'updated'       => 'Last updated:',
			),
			'es' => array(
				'by'            => 'Por',
				'key_takeaways' => 'Puntos clave',
				'toc'           => 'En esta página',
				'faq'           => 'Preguntas frecuentes',
				'sources'       => 'Fuentes',
				'updated'       => 'Última actualización:',
			),
			'pt' => array(
				'by'            => 'Por',
				'key_takeaways' => 'Pontos principais',
				'toc'           => 'Nesta página',
				'faq'           => 'Perguntas frequentes',
				'sources'       => 'Fontes',
				'updated'       => 'Última atualização:',
			),
			'fr' => array(
				'by'            => 'Par',
				'key_takeaways' => 'À retenir',
				'toc'           => 'Sur cette page',
				'faq'           => 'Questions fréquentes',
				'sources'       => 'Sources',
				'updated'       => 'Dernière mise à jour :',
			),
		);

		$code = strtolower( substr( (string) get_locale(), 0, 2 ) );
		$set  = isset( $labels[ $code ] ) ? $labels[ $code ] : $labels['en'];

		return isset( $set[ $key ] ) ? $set[ $key ] : $labels['en'][ $key ];
	}

	/**
	 * Strip diacritics so keyword matching survives Spanish spelling.
	 *
	 * A focus keyword is typed the way people search ("que es serigrafia"),
	 * while the copy is written properly ("Qué es serigrafía"). Comparing them
	 * literally reports the keyword as missing when it is right there.
	 */
	public static function fold( $text ) {
		$map = array(
			'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
			'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
			'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
			'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
			'ñ' => 'n', 'ç' => 'c', 'ý' => 'y',
		);

		return strtr( self::lower( $text ), $map );
	}
}
