<?php

/**
 * @file mimeMail.php
 *
 * <p>Released under a BSD-license. For complete license text see
 * {@link http://code.sgraastra.net/BSD.txt}</p>
 *
 * @author Thijs Putman <thijs@studyportals.eu>
 * @author Rob van den Hout <vdhout@studyportals.eu>
 * @author Danko Adamczyk <danko@studyportals.com>
 * @copyright © 2004-2009 Thijs Putman, all rights reserved.
 * @copyright © 2010-2014 StudyPortals B.V., all rights reserved.
 * @version 1.3.5
 */

namespace StudyPortals\Mail;

use Aws\Result;
use Aws\Ses\SesClient;
use Exception;
use StudyPortals\Exception\ExceptionHandler;

/**
 * Allows sending MIME e-mails.
 *
 * <p>Adheres to RFC 2822 (and related specifications) and uses RFC 2045 to
 * send multi-part messages (c.q. messages containing both plain-text, HTML
 * and/or attachements).</p>
 *
 * <p>The e-mail specification is rather "big" and all-encompassing, so it is
 * very well possible corner cases are not properly covered by this class. This
 * class has although been used to send countless e-mails over the past five
 * years (and has been tweaked whenever problems were ecountered) so you can
 * consider it relatively stable.</p>
 *
 * <p><strong>Important:</strong>Currently, this class is limited to sending
 * out ISO-8859-1 encoded e-mails. This limitation holds for both the header
 * fields <em>and</em> for the e-mail body. If you pass in non-ISO-8859-1
 * encoded content strange things (including, but not-limited to, your e-mails
 * being marked as spam, getting bounced or appearing completely illegible to
 * the receiving party) will happen!</p>
 *
 * @package Sgraastra.Framework
 * @subpackage Mail
 * @see http://www.ietf.org/rfc/rfc2822.txt
 * @see http://tools.ietf.org/html/rfc2045
 */

class mimeMail{

	const LINE_WIDTH = 78;
	const CHECK_PHP_OS = false;

	protected $_to_mail = [];

	protected $_to = [];
	protected $_cc = [];
	protected $_bcc = [];

	protected $_subject;
	protected $_from;
	protected $_reply_to;

	protected $_parts_rel = [];
	protected $_parts_alt = [];
	protected $_xheaders = [];

	protected $_sent_date;

	protected static $_mime_types = [
		'message/rfc822',
		'text/html',
		'text/plain',
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/bmp',
		'application/pdf',
		'application/msword',
		'application/zip'];

	/**
	 * Add a recipient.
	 *
	 * @param string $mail
	 * @param string $name
	 * @throws mimeMailException
	 * @return void
	 */

	public function addTo($mail, $name = null){

		// Mail function only accepts plain e-mail addresses as argument for the "to" field

		$this->_to_mail[] = $this->_formatHeaderAddress($mail);

		$this->_to[] = $this->_formatHeaderAddress($mail, $name);
	}

	/**
	* Add an e-mail address as carbon-copy.
	*
	* @param string $mail
	* @param string $name
	* @throws mimeMailException
	* @return void
	*/

	public function addCC($mail, $name = null){

		$this->_cc[] = $this->_formatHeaderAddress($mail, $name);
	}

	/**
	* Add an e-mail address as blind carbon-copy.
	*
	* @param string $mail
	* @throws mimeMailException
	* @return void
	*/

	public function addBCC($mail){

		$this->_bcc[] = $this->_formatHeaderAddress($mail);
	}

	/**
	 * Set the sender of the message.
	 *
	 * @param string $mail
	 * @param string $name
	 * @param boolean $envelope_sender Attempt to set the specified address as enveloppe sender too
	 *
	 * @throws mimeMailException
	 *
	 * @return void
	 */

	public function setFrom($mail, $name = null, $envelope_sender = false){

		if($envelope_sender && strtoupper(substr(PHP_OS, 0, 3)) !== 'LIN'){

            if(!ini_set('sendmail_from', $this->_formatHeaderAddress($mail))){

                throw new mimeMailException('Unable to set "From" information,
                failed to update envelope sender');
            }
		}

		$this->_from = $this->_formatHeaderAddress($mail, $name);
	}

	/**
	 * Set an e-mail address as reply-to.
	 *
	 * @param string $mail
	 * @param string $name
	 *
	 * @throws mimeMailException
	 * @return void
	 */

	public function setReplyTo($mail, $name = null){

		$this->_reply_to = $this->_formatHeaderAddress($mail, $name);
	}

	/**
	 * Set the subject of the message.
	 *
	 * @param string $subject
	 * @return void
	 */

	public function setSubject($subject){

		$this->_subject = $this->_formatHeader($subject);

		// Fold long subject

		if(strlen($this->_subject) > self::LINE_WIDTH){

			$this->_subject = "\r\n\t" . wordwrap($this->_subject, self::LINE_WIDTH, "\r\n\t", false);
		}
	}

	/**
	 * Add an additional header to the message.
	 *
	 * <p>Please note that you can only add custom headers (starting with "X-")
	 * using this method.</p>
	 *
	 * @param string $field
	 * @param string $body
	 * @return void
	 */

	public function addHeader($field, $body){

		$field = $this->_formatHeader($field);
		$field = str_replace(':', '', $field);

		if(substr(strtolower($field), 0, 2) != 'x-') $field = "X-$field";

		$this->_xheaders[$field] = $this->_formatHeader($body);
	}

	/**
	 * Format a string for use in an e-mail header according to RFC 2822.
	 *
	 * @param string $content
	 * @param boolean $quoted_string Add quotes if necessary
	 * @return string
	 * @see http://www.ietf.org/rfc/rfc2822.txt
	 */

	protected function _formatHeader($content, $quoted_string = false){

		$content = str_replace(["\r", "\n"], '', trim($content));

		// Apply encoded-word encoding if characters outside of the ASCII range are present

		if($content != iconv('ISO-8859-1', 'ASCII', $content)){

			$content = $this->_encodeWord($content);
		}

		// Optionally quote string

		if($quoted_string && strpos($content, ' ') !== false){

			$content = '"' . addslashes($content) . '"';
		}

		return $content;
	}

	/**
	 * Format an address header (From, To, etc.) according to RFC 2822.
	 *
	 * @param string $mail
	 * @param string $name
	 * @throws mimeMailException
	 * @return string
	 */

	protected function _formatHeaderAddress($mail, $name = null){

		if(strpos($mail, 'studyportals.invalid')){

			throw new mimeMailException('Never ever send mail to a
				studyportals.invalid email address.');
		}

		$mail = trim($mail);

		if(!filter_var(
			$mail,
			FILTER_CALLBACK,
			['options' => 'StudyPortals\Framework\Utils\Text::isValidEMail']
		)){

			throw new mimeMailException("Unable to format address header,
				'$mail' is an invalid e-mail address");
		}

		$mail = filter_var($mail, FILTER_SANITIZE_EMAIL);

		if(!is_null($name)){

			$name = trim($name);

			return $this->_formatHeader($name, true) . " <$mail>";
		}

		return $mail;
	}

	/**
	 * Apply encoded-word encoding in accordance with RFC 2047.
	 *
	 * <p>The provided {@link string} is quoted-printable encoded in chunks of
	 * {@link mimeMail::LINE_WIDTH} length. Chunks are seperated by a single
	 * space. This allows wordwrap() to properly wrap the encoded string to
	 * the required line width.</p>
	 *
	 * <p>This method is intended solely for use with <strong>header</strong>
	 * fields. To apply quoted-printable encoding to an e-mail body simply use
	 * the quoted_printable_encode() function.</p>
	 *
	 * @param $string
	 * @return string
	 * @see http://tools.ietf.org/html/rfc2047
	 */

	protected function _encodeWord($string){

		$folded_string = '=?iso-8859-1?q?';

		/*
		 * PHP's built-in quoted_printable_encode() function has the bad (and
		 * badly documented) habit of breaking up the string into lines of
		 * 76 characters (using "soft" line-breaks: A CRLF preceeded by an
		 * equals sign). This is required by RFC 2047, but not what we want in
		 * this case, so it needs to be undone before continuing.
		 */

		$string = quoted_printable_encode($string);
		$string = str_replace("=\r\n", '', $string);

		// Adjust line width for encoding markers and (the optional) leading WSP character

		$line_width = self::LINE_WIDTH - (strlen($folded_string) + 3);

		while(strlen($string) > $line_width){

			$cut_at = $line_width;

			if(substr($string, $line_width - 1, 1) == '='){

				$cut_at = $line_width - 1;
			}

			elseif(substr($string, $line_width - 2, 1) == '='){

				$cut_at = $line_width - 2;
			}

			$folded_string .= substr($string, 0, $cut_at) . '?= =?iso-8859-1?q?';
			$string = substr($string, $cut_at);
		}

		$folded_string .= "$string?=";

		return $folded_string;
	}

	/**
	 * Add message content to the e-mail.
	 *
	 * <p>The parameter {@link $type} should be set to either "html" or "plain"
	 * to indicate the content is formatted as HTML or plain-text respectively.
	 * The type defaults to HTML.</p>
	 *
	 * <p><strong>Important:</strong> The {@link $content} string is
	 * <em>required</em> to be encoded using ISO-8859-1!</p>
	 *
	 * @param string $content
	 * @param string $type
	 * @return void
	 * @throws mimeMailException
	 */

	public function addMessage($content, $type = 'html'){

		// Prevent superfluous tab-characters from interfering with the word-wrap

		if(strtolower($type) == 'html'){

			$content = preg_replace('/[\t]+/', ' ', $content);}

		// Remove bare LR/LF's

		$content = preg_replace("/\r\n?|\r?\n/", "\r\n", $content);

		// Quoted-printable encoding

		$content = quoted_printable_encode($content);

		/*
		 * Split lines to 78 characters.
		 *
		 * Although the previous call to quote_printable_encode() already takes
		 * care of this (which is *not* clear from its documentation by the way)
		 * it's better to be safe than sorry (mail servers and spam filters are
		 * very picky about these kinds of things...).
		 */

		$content = wordwrap($content, self::LINE_WIDTH, "\r\n", true);
		$content = rtrim($content);

		// On Windows: Prevent SMTP server from removing full stop (.) characters at the start of a line

		if(self::CHECK_PHP_OS && strtolower(substr(PHP_OS, 0, 3)) == 'win'){

			$content = str_replace("\r\n.", "\r\n..", $content);
		}

		// Add the correct MIME-part

		switch(strtolower($type)){

			// HTML

			case 'html':

				$this->_parts_alt[$type]['header'] = [
					'Content-Type: text/html; charset=iso-8859-1',
					'Content-Transfer-Encoding: quoted-printable'];

				$this->_parts_alt[$type]['body'] = $content;

			break;

			// Plain-Text

			case 'plain':

				$this->_parts_alt[$type]['header'] = [
					'Content-Type: text/plain; charset=iso-8859-1',
					'Content-Transfer-Encoding: quoted-printable'];

				$this->_parts_alt[$type]['body'] = $content;

			break;

			default:

				throw new mimeMailException('Unable to add alternative part
					of type "' . $type. '", unknown type');
		}
	}

	/**
	 * Add an attachment to the e-mail.
	 *
	 * <p>The parameter {@link $disposition} should be set to either
	 * "attachment" or "inline" to indicate the disposition of the file attached.
	 * Files with disposition "attachment" will show up as attachments in the
	 * e-mail client, whilst "inline" files are now shown.</p>
	 *
	 * <p>If {@link $unique_id} is not specified this method will generate a
	 * random unique ID. This methods returns the unique ID of the file attached.
	 * In the HTML content of the message, the file can be referred to by using
	 * "cid:unique ID".</p>
	 *
	 * @param string $filename
	 * @param string $mime_type
	 * @param string $content
	 * @param string $disposition
	 * @param string $unique_id
	 *
	 * @throws mimeMailException
	 *
	 * @return string
	 */

	public function addAttachement($filename, $mime_type, $content, $disposition = 'attachment', $unique_id = null){

		switch(strtolower($disposition)){

			case 'attachment':
			case 'inline':

			break;

			default:

				throw new mimeMailException('Unable to add "' . $filename
					. '", invalid content disposition "' . $disposition . '"');
		}

		if(is_null($unique_id)) {

			$unique_id = md5(uniqid($filename, true)) . '@' . $_SERVER['HTTP_HOST'];
		}

		if(!in_array($mime_type, self::$_mime_types)){

			throw new mimeMailException('Unable to add "' . $filename
				. '", MIME type "' . $mime_type . '" is invalid');
		}

		$filename = $this->_formatHeader($filename, true);

		$this->_parts_rel[$unique_id]['header'] = [
			"Content-Type: $mime_type; name=$filename",
			'Content-Transfer-Encoding: base64',
			"Content-ID: <$unique_id>",
			"Content-Disposition: $disposition; filename=$filename"];

		$this->_parts_rel[$unique_id]['body'] = rtrim(chunk_split(base64_encode($content), 76, "\r\n"));

		return $unique_id;
	}

	/**
	 * Compose the MIME message.
	 *
	 * <p>This method returns an array containing two elements. The first element
	 * is the composed header of the message, the second element is the composed
	 * body of the message.</p>
	 *
	 * @return array
	 * @throws mimeMailException
	 */

	protected function _composeMessage(){

		// Boundaries

		$boundary_rel = 'Rel__' . md5(uniqid('rel', true));
		$boundary_alt = 'Alt__' . md5(uniqid('alt', true));

		// Construct the header

		$header = [];

		if(count($this->_to_mail) == 0){

			throw new mimeMailException('Unable to send message,
				no recipient address set');
		}

		$header[] = 'To: ' . join(', ', $this->_to);

		if(count($this->_cc) > 0){

			$header[] = 'Cc: ' . join(', ', $this->_cc);
		}

		if(count($this->_bcc) > 0){

			$header[] = 'Bcc: ' . join(', ', $this->_bcc);
		}

		if(empty($this->_from)){

			throw new mimeMailException('Unable to send message,
				no from address set');
		}

		$header[] = "From: $this->_from";

		if($this->_reply_to){

			$header[] = "Reply-To: $this->_reply_to";
		}

		$header[] = "Return-Path: $this->_from";
		$header[] = 'MIME-Version: 1.0';
		$header[] = "Content-Type: multipart/related; boundary=\"$boundary_rel\"";

		foreach($this->_xheaders as $field => $body){

			$header[] = "$field: $body";
		}

		// Construct the body

		$body_rel = [];
		$body_alt = [];
		$body = ["This is a MIME e-mail\r\n"];

		// Relative parts

		foreach($this->_parts_rel as $part_rel){

			$body_rel[] = "--$boundary_rel";
			$body_rel[] = $this->_composeHeader((array) $part_rel['header']) . "\r\n";
			$body_rel[] = $part_rel['body'];
		}

		// Alternative parts

		if(isset($this->_parts_alt['plain'])){

			$part_alt = $this->_parts_alt['plain'];
			$body_alt[] = "--$boundary_alt";
			$body_alt[] = $this->_composeHeader((array) $part_alt['header']) . "\r\n";
			$body_alt[] = $part_alt['body'];
		}

		if(isset($this->_parts_alt['html'])){

			$part_alt = $this->_parts_alt['html'];
			$body_alt[] = "--$boundary_alt";
			$body_alt[] = $this->_composeHeader((array) $part_alt['header']) . "\r\n";
			$body_alt[] = $part_alt['body'];
		}

		// Combine relative and alternative parts

		$body[] = "--$boundary_rel";
		$body[] = "Content-Type: multipart/alternative;\r\n boundary=\"$boundary_alt\"\r\n";
		$body[] = implode("\r\n", $body_alt);
		$body[] =  "\r\n--$boundary_alt--\r\n";

		$body[] = implode("\r\n", $body_rel);
		$body[] = "\r\n--$boundary_rel--\r\n";

		return [$this->_composeHeader($header), implode("\r\n", $body)];
	}

	/**
	 * Compose a MIME header.
	 *
	 * <p>This method composes the header elements into a single string, properly
	 * folding the lines in accordance with RFC 2822.</p>
	 *
	 * @param array $header
	 * @return string
	 */

	protected function _composeHeader(array $header){

		// Fold long headers

		foreach($header as $key => $element){

			if(strlen($element) > self::LINE_WIDTH){

				$header[$key] = wordwrap($element, self::LINE_WIDTH, "\r\n\t", false);

				if(strpos($header[$key], "\r\n\t") > self::LINE_WIDTH){

					$header[$key] = str_replace(': ', ":\r\n\t", $header[$key]);
				}
			}
		}

		return implode("\r\n", $header);
	}

	/**
	 * Send the message.
	 *
	 * @return boolean
	 */

	public function send(){

		try{

			if($this->sendAWS()){

				return true;
			}
			else{

				ExceptionHandler::notice('sendAWS failed');
			}
		}
		catch(Exception $e){

			ExceptionHandler::notice($e->getMessage());
		}

		try{

			list($header, $body) = $this->_composeMessage();
		}
		catch(mimeMailException $e){

			return false;
		}

		$this->_performTestingRewrite();

		$result = @mail(implode(',', $this->_to_mail), $this->_subject, $body, $header);
		$this->_sent_date = time();

		return $result ? true : false;
	}

	/**
	 * Send the message.
	 *
	 * @return boolean
	 */

	public function sendAWS(){

		$this->_performTestingRewrite();

		$message = $this->render();

		$Client = new SesClient([
			'version' => '2010-12-01',
			'credentials' => [
				'key' => SP_AWS_SES_KEY,
				'secret' => SP_AWS_SES_SECRET,
			],
			'region'  => SP_AWS_SES_REGION
		]);

		$Response = $Client->sendRawEmail([
			'RawMessage' => [
				'Data' => $message
			]
		]);

		$this->_sent_date = time();

		return $Response instanceof Result;

	}

	/**
	 * Render the message (but do <em>not</em> send it).
	 *
	 * <p>This method renders out the e-mail message as if it was send out to
	 * (and received by) the recipient. This is useful for loggin the message
	 * without actually sending it through the mail server.</p>
	 *
	 * <p><strong>Note:</strong> Although the overall message content is the
	 * same as that of the actually send out message, certain elements (such as
	 * multipart ID's) will differ as the message is re-rendered (c.q. we do
	 * not store a carbon-copy of the message send out).</p>
	 *
	 * <p>The optional, passed-by-reference, argument can be used to force a
	 * certain timestamp upon the e-mail message. When not provided the time
	 * the e-mail was sent (if available) or the current time is used. As the
	 * parameter is passed-by-reference it can be used to retrieve the time
	 * from this method for use in further logging.</p>
	 *
	 * @param integer $time
	 * @return string
	 */

	public function render(&$time = null){

		$message = '';

		try{

			list($header, $body) = $this->_composeMessage();
		}
		catch(mimeMailException $e){

			return $message;
		}

		if(empty($time)){

			$time = (!empty($this->_sent_date) ? $this->_sent_date : time());
		}

		// Add headers normally provided by PHP or the Mail Transfer Agent

		$message .= "Subject: {$this->_subject}\r\n";
		$message .= 'Date: ' . date('r', $time) . "\r\n";

		$message .= $header;
		$message .= "\r\n\r\n";
		$message .= $body;

		return $message;
	}

	/**
	 * Perform rewrite of email address when it is a testing email address.
	 *
	 * <p>All email addresses that match "testing+<something>@studyportals.com"
	 * will be rewritten to testing@studyportals.com as to receive it in our
	 * public testing email box.</p>
	 *
	 * <p>The testing email address in the "to" field will have their
	 * <something> prepended to the subject.</p>
	 *
	 * @return void
	 */

	protected function _performTestingRewrite(){

		foreach($this->_to_mail as $email){

			$matches = [];

			$matched = preg_match(
				'/testing\+(.*)@studyportals\.(?:eu|com)/',
				$email,
				$matches
			);

			if($matched === 1){

				$this->_subject = $matches[1] . ', ' . $this->_subject;
			}
		}

		$rewrite = function($email){

			return preg_replace(
				'/testing\+.*@studyportals\.(eu|com)/',
				'testing@studyportals.com',
				$email
			);
		};

		$this->_to_mail = array_map($rewrite, $this->_to_mail);
		$this->_to = array_map($rewrite, $this->_to);
		$this->_cc = array_map($rewrite, $this->_cc);
		$this->_bcc = array_map($rewrite, $this->_bcc);
	}
}