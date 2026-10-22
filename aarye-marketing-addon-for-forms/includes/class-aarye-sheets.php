<?php
/**
 * Google Sheets: every new entry is added as a row, through a small Google Apps Script web app in the
 * user's own spreadsheet (no Google login or API keys stored in WordPress).
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Google Sheets.
 */
class AARYE_Sheets {

	const QUEUE = 'aarye_sheets_queue';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'aarye_entry_saved', array( __CLASS__, 'on_entry' ), 20 );
		add_action( 'aarye_hourly', array( __CLASS__, 'retry' ) );
	}

	/**
	 * Whether Google Sheets is set up.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== (string) AARYE_Settings::get( 'sheets_url' ) && '' !== (string) AARYE_Settings::get( 'sheets_secret' );
	}

	/**
	 * Send a new entry.
	 *
	 * @param int $id Entry ID.
	 */
	public static function on_entry( $id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$entry = AARYE_Entries::get( $id );
		if ( ! $entry || ( $entry->is_bot && ! AARYE_Settings::on( 'sheets_bots' ) ) ) {
			return;
		}
		if ( ! self::send( $entry ) ) {
			$queue   = (array) get_option( self::QUEUE, array() );
			$queue[] = array(
				'id'    => (int) $id,
				'tries' => 1,
			);
			update_option( self::QUEUE, array_slice( $queue, -500 ), false );
		}
	}

	/**
	 * Try failed rows again (hourly, up to 24 times).
	 */
	public static function retry() {
		$queue = (array) get_option( self::QUEUE, array() );
		if ( ! $queue || ! self::enabled() ) {
			return;
		}
		$left = array();
		foreach ( array_slice( $queue, 0, 50 ) as $item ) {
			$entry = AARYE_Entries::get( $item['id'] );
			if ( $entry && ! self::send( $entry ) && $item['tries'] < 24 ) {
				$item['tries']++;
				$left[] = $item;
			}
		}
		update_option( self::QUEUE, array_merge( $left, array_slice( $queue, 50 ) ), false );
	}

	/**
	 * The row for an entry: column title => value. Every form field gets its own column.
	 *
	 * @param object $entry Entry.
	 * @return array<string, string>
	 */
	public static function row( $entry ) {
		$row = array(
			'Entry ID'    => (string) $entry->id,
			'Date'        => get_date_from_gmt( $entry->created_at, 'Y-m-d H:i:s' ),
			'Form'        => $entry->form_name,
			'Name'        => $entry->name,
			'Email'       => $entry->email,
			'Phone'       => $entry->phone,
			'Source'      => $entry->source_label,
			'Channel'     => AARYE_Sources::channel_label( $entry->channel ),
			'From an ad'  => 'paid' === $entry->channel ? 'Yes' : 'No',
			'Campaign'    => $entry->campaign,
			'Ad set / ad group' => $entry->ad_group,
			'Ad'          => $entry->ad,
			'Keyword'     => $entry->keyword,
			'Click ID'    => '' !== $entry->click_id ? $entry->click_param . '=' . $entry->click_id : '',
			'Landing page' => (string) $entry->landing_page,
			'Referrer'    => (string) $entry->referrer,
			'Form page'   => (string) $entry->page_url,
			'Language'    => $entry->language,
			'Device'      => $entry->device,
			'Visits before'  => (string) $entry->visits,
		);
		foreach ( AARYE_Entries::json( $entry, 'fields' ) as $field ) {
			$title = '' !== $field['label'] ? $field['label'] : $field['name'];
			if ( isset( $row[ $title ] ) ) {
				$title = 'Field: ' . $title;
			}
			$row[ $title ] = $field['value'];
		}
		$links = array();
		foreach ( AARYE_Entries::json( $entry, 'files' ) as $i => $file ) {
			$links[] = '' !== $file['url'] ? $file['url'] : AARYE_Admin::file_url( $entry->id, $i );
		}
		if ( $links ) {
			$row['Files'] = implode( "\n", $links );
		}
		// Spreadsheets treat text starting with = + - @ as formulas: keep values as plain text.
		foreach ( $row as $key => $value ) {
			if ( '' !== $value && in_array( substr( $value, 0, 1 ), array( '=', '+', '-', '@' ), true ) ) {
				$row[ $key ] = "'" . $value;
			}
		}
		/**
		 * Change the Google Sheets row of an entry.
		 *
		 * @param array  $row   Column title => value.
		 * @param object $entry Entry.
		 */
		return (array) apply_filters( 'aarye_sheets_row', $row, $entry );
	}

	/**
	 * Post to the web app.
	 *
	 * @param object|null $entry Entry, or null for a connection test.
	 * @param bool        $wait  Wait for the reply.
	 * @return bool|string True on success, error message when waiting.
	 */
	public static function send( $entry, $wait = true ) {
		$body = array( 'secret' => (string) AARYE_Settings::get( 'sheets_secret' ) );
		if ( null === $entry ) {
			$body['test'] = true;
		} else {
			$row             = self::row( $entry );
			$body['sheet']   = AARYE_Settings::on( 'sheets_tab_per_form' ) ? mb_substr( preg_replace( '/[\[\]\*\?\/\\\\:]/', ' ', $entry->form_name ? $entry->form_name : 'Leads' ), 0, 90 ) : 'Leads';
			$body['headers'] = array_keys( $row );
			$body['row']     = $row;
		}
		$response = wp_remote_post(
			(string) AARYE_Settings::get( 'sheets_url' ),
			array(
				'timeout'     => 15,
				'blocking'    => $wait,
				'redirection' => 5,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( ! $wait ) {
			return true;
		}
		if ( is_wp_error( $response ) ) {
			return null === $entry ? $response->get_error_message() : false;
		}
		$reply = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $reply ) && ! empty( $reply['ok'] ) ) {
			return true;
		}
		if ( null === $entry ) {
			return is_array( $reply ) && ! empty( $reply['error'] ) ? (string) $reply['error'] : __( 'Google did not answer as expected. Check that the web app is deployed with access "Anyone" and that you pasted the /exec address.', 'aarye-marketing-addon-for-forms' );
		}
		return false;
	}

	/**
	 * The Apps Script to paste into the spreadsheet.
	 *
	 * @return string
	 */
	public static function script() {
		$secret = (string) AARYE_Settings::get( 'sheets_secret' );
		return <<<JS
// Aarye Marketing Addon for Forms – adds each form entry as a row in this spreadsheet.
const SECRET = '{$secret}';

function doPost(e) {
  const data = JSON.parse(e.postData.contents);
  if (data.secret !== SECRET) return reply({ ok: false, error: 'The secret does not match. Copy the script again from WordPress.' });
  if (data.test) return reply({ ok: true });

  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const book = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = book.getSheetByName(data.sheet) || book.insertSheet(data.sheet);
    let headers = sheet.getLastColumn() ? sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0] : [];
    data.headers.forEach(function (h) {
      if (headers.indexOf(h) === -1) {
        headers.push(h);
        sheet.getRange(1, headers.length).setValue(h).setFontWeight('bold');
      }
    });
    if (sheet.getFrozenRows() === 0) sheet.setFrozenRows(1);
    sheet.appendRow(headers.map(function (h) { return data.row[h] !== undefined ? data.row[h] : ''; }));
  } finally {
    lock.releaseLock();
  }
  return reply({ ok: true });
}

function reply(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
JS;
	}
}
