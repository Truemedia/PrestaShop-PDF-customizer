<?php

/**
  * PDF class, PDF.php
  * PDF invoices and document management
  * @category classes
  *
  * @author PrestaShop <support@prestashop.com>
  * @copyright PrestaShop
  * @license http://www.opensource.org/licenses/osl-3.0.php Open-source licence 3.0
  * @version 1.3
  *
  */

include_once(_PS_FPDF_PATH_.'fpdf.php');

class PDF_PageGroup extends FPDF
{
	var $NewPageGroup;   // variable indicating whether a new group was requested
	var $PageGroups;	 // variable containing the number of pages of the groups
	var $CurrPageGroup;  // variable containing the alias of the current page group

	// create a new page group; call this before calling AddPage()
	function StartPageGroup()
	{
		$this->NewPageGroup=true;
	}

	// current page in the group
	function GroupPageNo()
	{
		return $this->PageGroups[$this->CurrPageGroup];
	}

	// alias of the current page group -- will be replaced by the total number of pages in this group
	function PageGroupAlias()
	{
		return $this->CurrPageGroup;
	}

	function _beginpage($orientation, $arg2)
	{
		parent::_beginpage($orientation, $arg2);
		if($this->NewPageGroup)
		{
			// start a new group
			$n = sizeof($this->PageGroups)+1;
			$alias = "{nb$n}";
			$this->PageGroups[$alias] = 1;
			$this->CurrPageGroup = $alias;
			$this->NewPageGroup=false;
		}
		elseif($this->CurrPageGroup)
			$this->PageGroups[$this->CurrPageGroup]++;
	}

	function _putpages()
	{
		$nb = $this->page;
		if (!empty($this->PageGroups))
		{
			// do page number replacement
			foreach ($this->PageGroups as $k => $v)
				for ($n = 1; $n <= $nb; $n++)
					$this->pages[$n]=str_replace($k, $v, $this->pages[$n]);
		}
		parent::_putpages();
	}
}

class PDF extends PDF_PageGroup
{
	private static $order = NULL;
	private static $orderReturn = NULL;
	private static $orderSlip = NULL;
	private static $delivery = NULL;
	private static $_priceDisplayMethod;

	/** @var object Order currency object */
	private static $currency = NULL;

	private static $_iso;

	/** @var array Special PDF params such encoding and font */

	private static $_pdfparams = array();
	private static $_fpdf_core_fonts = array('courier', 'helvetica', 'helveticab', 'helveticabi', 'helveticai', 'symbol', 'times', 'timesb', 'timesbi', 'timesi', 'zapfdingbats');

	/**
	* Constructor
	*/
	public function __construct($orientation='P', $unit='mm', $format='A4')
	{
		global $cookie;

		if (!isset($cookie) OR !is_object($cookie))
			$cookie->id_lang = intval(Configuration::get('PS_LANG_DEFAULT'));
		self::$_iso = strtoupper(Language::getIsoById($cookie->id_lang));
		FPDF::FPDF($orientation, $unit, $format);
		$this->_initPDFFonts();
	}

	private function _initPDFFonts()
	{
		if (!$languages = Language::getLanguages())
			die(Tools::displayError());
		foreach ($languages AS $language)
		{
			$isoCode = strtoupper($language['iso_code']);
			$conf = Configuration::getMultiple(array('PS_PDF_ENCODING_'.$isoCode, 'PS_PDF_FONT_'.$isoCode));
			self::$_pdfparams[$isoCode] = array(
				'encoding' => (isset($conf['PS_PDF_ENCODING_'.$isoCode]) AND $conf['PS_PDF_ENCODING_'.$isoCode] == true) ? $conf['PS_PDF_ENCODING_'.$isoCode] : 'iso-8859-1',
				'font' => (isset($conf['PS_PDF_FONT_'.$isoCode]) AND $conf['PS_PDF_FONT_'.$isoCode] == true) ? $conf['PS_PDF_FONT_'.$isoCode] : 'helvetica'
			);
		}

		if ($font = self::embedfont())
		{
			$this->AddFont($font);
			$this->AddFont($font, 'B');
		}
	}

	/**
	* Invoice header
	*/
	public function Header()
	{
		global $cookie;

		$conf = Configuration::getMultiple(array('PS_SHOP_NAME', 'PS_SHOP_ADDR1', 'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_COUNTRY', 'PS_SHOP_STATE'));
		$conf['PS_SHOP_NAME'] = isset($conf['PS_SHOP_NAME']) ? Tools::iconv('utf-8', self::encoding(), $conf['PS_SHOP_NAME']) : 'Your company';
		$conf['PS_SHOP_ADDR1'] = isset($conf['PS_SHOP_ADDR1']) ? Tools::iconv('utf-8', self::encoding(), $conf['PS_SHOP_ADDR1']) : 'Your company';
		$conf['PS_SHOP_CODE'] = isset($conf['PS_SHOP_CODE']) ? Tools::iconv('utf-8', self::encoding(), $conf['PS_SHOP_CODE']) : 'Postcode';
		$conf['PS_SHOP_CITY'] = isset($conf['PS_SHOP_CITY']) ? Tools::iconv('utf-8', self::encoding(), $conf['PS_SHOP_CITY']) : 'City';
		$conf['PS_SHOP_COUNTRY'] = isset($conf['PS_SHOP_COUNTRY']) ? Tools::iconv('utf-8', self::encoding(), $conf['PS_SHOP_COUNTRY']) : 'Country';
		$conf['PS_SHOP_STATE'] = isset($conf['PS_SHOP_STATE']) ? Tools::iconv('utf-8', self::encoding(), $conf['PS_SHOP_STATE']) : '';

		if (file_exists(_PS_IMG_DIR_.'/Logo_Invoice.jpg'))
			$this->Image(_PS_IMG_DIR_.'/Logo_Invoice.jpg', 4, 8, 0, 45);
		else if (file_exists(_PS_IMG_DIR_.'/logo.jpg'))
			$this->Image(_PS_IMG_DIR_.'/logo.jpg', 10, 8, 0, 15);
		$this->SetTextColor(240, 240, 240);
		$this->SetFont(self::fontname(), 'B', 15);
		$this->Cell(115);

		if (self::$orderReturn)
			$this->Cell(77, 10, self::l('RETURN #').' '.sprintf('%06d', self::$orderReturn->id), 0, 1, 'R');
		elseif (self::$orderSlip)
			$this->Cell(77, 10, self::l('SLIP #').' '.sprintf('%06d', self::$orderSlip->id), 0, 1, 'R');
		elseif (self::$delivery)
			$this->Cell(77, 10, self::l('DELIVERY SLIP #').' '.Tools::iconv('utf-8', self::encoding(), Configuration::get('PS_DELIVERY_PREFIX', (int)($cookie->id_lang))).sprintf('%06d', self::$delivery), 0, 1, 'R');
		elseif (self::$order->invoice_number){ // invoice template
			/*$delivery_address = new Address((int)($order->id_address_delivery));
			$this->SetFont(self::fontname(), '', 7);
			$this->Cell(77, 10, self::l('Delivery'), 1, 2, 'L', 1);
			// add extra stuff here*/
		}
		else
			$this->Cell(77, 10, self::l('ORDER #').' '.sprintf('%06d', self::$order->id), 0, 1, 'R');
   }

	/**
	* Invoice footer
	*/
	public function Footer()
	{
		$arrayConf = array('PS_SHOP_NAME', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2', 'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_COUNTRY', 'PS_SHOP_DETAILS', 'PS_SHOP_PHONE', 'PS_SHOP_STATE');
		$conf = Configuration::getMultiple($arrayConf);
		$conf['PS_SHOP_NAME_UPPER'] = Tools::strtoupper($conf['PS_SHOP_NAME']);
		$y_delta = array_key_exists('PS_SHOP_DETAILS', $conf) ? substr_count($conf['PS_SHOP_DETAILS'],"\n") : 0;
		$this->SetY( -33 - ($y_delta * 7));
		$this->SetFont(self::fontname(), '', 7);
		$this->Cell(190, 5, ' '."\n".Tools::iconv('utf-8', self::encoding(), 'Page ').$this->GroupPageNo().' / '.$this->PageGroupAlias(), 'T', 1, 'C');

		/*
		 * Display a message for customer
		 */
		foreach($conf as $key => $value)
			$conf[$key] = Tools::iconv('utf-8', self::encoding(), $value);
		foreach ($arrayConf as $key)
			if (!isset($conf[$key]))
				$conf[$key] = '';
		if (!self::$delivery)
		{
			$this->SetFont(self::fontname(), '', 8);
			if (self::$orderSlip)
				$textFooter = self::l('An electronic version of this invoice is available in your account. To access it, log in to the');
			else
				$textFooter = self::l('An electronic version of this invoice is available in your account. To access it, log in to the');
			$this->Cell(0, 10, $textFooter, 0, 0, 'C', 0);
			$this->Ln(4);
			$this->Cell(0, 10, self::l('website using your e-mail address and password (which you created when placing your first order).').$_SERVER[''].__PS_BASE_URI__.' | '.self::l('PHONE:').' '.$conf['PS_SHOP_PHONE'], 0, 0, 'C', 0);
		} //$this->Cell(0, 10, self::l('website using your e-mail address and password (which you created when placing your first order).').$_SERVER['SERVER_NAME'].__PS_BASE_URI__.' | '.self::l('PHONE:').' '.$conf['PS_SHOP_PHONE'], 0, 0, 'C', 0);
		else
			$this->Ln(4);
		$this->Ln(3);
		$this->SetFillColor(240, 240, 240);
		$this->SetTextColor(0, 0, 0);
		$this->SetFont(self::fontname(), '', 8);
		$this->Ln(4);
		$this->Cell(0, 5,
		(!empty($conf['PS_SHOP_ADDR1']) ? '  '.' '.$conf['PS_SHOP_ADDR1'].(!empty($conf['PS_SHOP_ADDR2']) ? ' '.$conf['PS_SHOP_ADDR2'] : '').' '.$conf['PS_SHOP_CODE'].' '.$conf['PS_SHOP_CITY'].((isset($conf['PS_SHOP_STATE']) AND !empty($conf['PS_SHOP_STATE'])) ? (', '.$conf['PS_SHOP_STATE']) : '').' '.$conf['PS_SHOP_COUNTRY'] : ''), 0, 1, 'C');
		$this->Cell(0, 10, $conf['PS_SHOP_NAME'], 0, 0, 'C', 0);
		$this->Ln(4);
		$this->Cell(0, 10, $_SERVER['SERVER_NAME'].__PS_BASE_URI__, 0, 0, 'C', 0);
	}

	public static function multipleInvoices($invoices)
	{
		$pdf = new PDF('P', 'mm', 'A4');
		foreach ($invoices AS $id_order)
		{
			$orderObj = new Order(intval($id_order));
			if (Validate::isLoadedObject($orderObj))
				PDF::invoice($orderObj, 'D', true, $pdf);
		}
		return $pdf->Output('invoices.pdf', 'D');
	}

	public static function multipleDelivery($slips)
	{
		$pdf = new PDF('P', 'mm', 'A4');
		foreach ($slips AS $id_order)
		{
			$orderObj = new Order(intval($id_order));
			if (Validate::isLoadedObject($orderObj))
				PDF::invoice($orderObj, 'D', true, $pdf, false, $orderObj->delivery_number);
		}
		return $pdf->Output('invoices.pdf', 'D');
	}

	public static function orderReturn($orderReturn, $mode = 'D', $multiple = false, &$pdf = NULL)
	{
		$pdf = new PDF('P', 'mm', 'A4');
		self::$orderReturn = $orderReturn;
		$order = new Order($orderReturn->id_order);
		self::$order = $order;
		$pdf->SetAutoPageBreak(true, 35);
		$pdf->StartPageGroup();
		$pdf->AliasNbPages();
		$pdf->AddPage();

		/* Display address information */
		$delivery_address = new Address(intval($order->id_address_delivery));
		$deliveryState = $delivery_address->id_state ? new State($delivery_address->id_state) : false;
		$shop_country = Configuration::get('PS_SHOP_COUNTRY');
		$arrayConf = array('PS_SHOP_NAME', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2', 'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_COUNTRY', 'PS_SHOP_DETAILS', 'PS_SHOP_PHONE', 'PS_SHOP_STATE');
		$conf = Configuration::getMultiple($arrayConf);
		foreach ($conf as $key => $value)
			$conf[$key] = Tools::iconv('utf-8', self::encoding(), $value);
		foreach ($arrayConf as $key)
			if (!isset($conf[$key]))
				$conf[$key] = '';

		$width = 100;
		$pdf->SetX(10);
		$pdf->SetY(25);
		$pdf->SetFont(self::fontname(), '', 9);

		if (!empty($delivery_address->company))
		{
			$pdf->Cell($width, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->company), 0, 'L');
			$pdf->Ln(5);
		}
		$pdf->Cell($width, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->firstname).' '.Tools::iconv('utf-8', self::encoding(), $delivery_address->lastname), 0, 'L');
		$pdf->Ln(5);
		$pdf->Cell($width, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->address1), 0, 'L');
		$pdf->Ln(5);
		if (!empty($delivery_address->address2))
		{
			$pdf->Cell($width, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->address2), 0, 'L');
			$pdf->Ln(5);
		}
		$pdf->Cell($width, 10, $delivery_address->postcode.' '.Tools::iconv('utf-8', self::encoding(), $delivery_address->city), 0, 'L');
		$pdf->Ln(5);
		$pdf->Cell($width, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->country.($deliveryState ? ' - '.$deliveryState->name : '')), 0, 'L');

		/*
		 * display order information
		 */
		$pdf->Ln(12);
		$pdf->SetFillColor(240, 240, 240);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont(self::fontname(), '', 9);
		$pdf->Cell(0, 6, self::l('RETURN #').sprintf('%06d', self::$orderReturn->id).' '.self::l('from') . ' ' .Tools::displayDate(self::$orderReturn->date_upd, self::$order->id_lang), 1, 2, 'L');
		$pdf->Cell(0, 6, self::l('We have logged your return request.'), 'TRL', 2, 'L');
		$pdf->Cell(0, 6, self::l('We remind you that your package must be returned to us within').' '.Configuration::get('PS_ORDER_RETURN_NB_DAYS').' '.self::l('days of initially receiving your order.'), 'BRL', 2, 'L');
		$pdf->Ln(5);
		$pdf->Cell(0, 6, self::l('List of items marked as returned :'), 0, 2, 'L');
		$pdf->Ln(5);
		$pdf->ProdReturnTab();
		$pdf->Ln(5);
		$pdf->SetFont(self::fontname(), 'B', 10);
		$pdf->Cell(0, 6, self::l('Return reference:').' '.self::l('RET').sprintf('%06d', self::$order->id), 0, 2, 'C');
		$pdf->Cell(0, 6, self::l('Thank you for including this number on your return package.'), 0, 2, 'C');
		$pdf->Ln(5);
		$pdf->SetFont(self::fontname(), 'B', 9);
		$pdf->Cell(0, 6, self::l('REMINDER:'), 0, 2, 'L');
		$pdf->SetFont(self::fontname(), '', 9);
		$pdf->Cell(0, 6, self::l('- All products must be returned in their original packaging without damage or wear.'), 0, 2, 'L');
		$pdf->Cell(0, 6, self::l('- Please print out this document and slip it into your package.'), 0, 2, 'L');
		$pdf->Cell(0, 6, self::l('- The package should be sent to the following address:'), 0, 2, 'L');
		$pdf->Ln(5);
		$pdf->SetFont(self::fontname(), 'B', 10);
		$pdf->Cell(0, 5, Tools::strtoupper($conf['PS_SHOP_NAME']), 0, 1, 'C', 1);
		$pdf->Cell(0, 5, (!empty($conf['PS_SHOP_ADDR1']) ? self::l('Headquarters:').' '.$conf['PS_SHOP_ADDR1'].(!empty($conf['PS_SHOP_ADDR2']) ? ' '.$conf['PS_SHOP_ADDR2'] : '').' '.$conf['PS_SHOP_CODE'].' '.$conf['PS_SHOP_CITY'].' '.$conf['PS_SHOP_COUNTRY'].((isset($conf['PS_SHOP_STATE']) AND !empty($conf['PS_SHOP_STATE'])) ? (', '.$conf['PS_SHOP_STATE']) : '') : ''), 0, 1, 'C', 1);
		$pdf->Ln(5);
		$pdf->SetFont(self::fontname(), '', 9);
		$pdf->Cell(0, 6, self::l('Upon receiving your package, we will inform you by e-mail and will then begin processing the reimbursement of your order total.'), 0, 2, 'L');
		$pdf->Cell(0, 6, self::l('Let us know if you have any questions.'), 0, 2, 'L');
		$pdf->Ln(5);
		$pdf->SetFont(self::fontname(), 'B', 10);
		$pdf->Cell(0, 6, self::l('If the conditions of return listed above are not respected,'), 'TRL', 2, 'C');
		$pdf->Cell(0, 6, self::l('we reserve the right to refuse your package and/or reimbursement.'), 'BRL', 2, 'C');

		return $pdf->Output(sprintf('%06d', self::$order->id).'.pdf', $mode);
	}

	/**
	* Product table with references, quantities...
	*/
	public function ProdReturnTab()
	{
		$header = array(
			array(self::l('Description'), 'L'),
			array(self::l('Reference'), 'L'),
			array(self::l('Qty'), 'C')
		);
		$w = array(110, 25, 20);
		$this->SetFont(self::fontname(), 'B', 8);
		$this->SetFillColor(240, 240, 240);
		for ($i = 0; $i < sizeof($header); $i++)
			$this->Cell($w[$i], 5, $header[$i][0], 'T', 0, $header[$i][1], 1);
		$this->Ln();
		$this->SetFont(self::fontname(), '', 7);

		$products = OrderReturn::getOrdersReturnProducts(self::$orderReturn->id, self::$order);
		foreach ($products AS $product)
		{
			$before = $this->GetY();
			$this->MultiCell($w[0], 5, Tools::iconv('utf-8', self::encoding(), $product['product_name']), 'B');
			$lineSize = $this->GetY() - $before;
			$this->SetXY($this->GetX() + $w[0], $this->GetY() - $lineSize);
			$this->Cell($w[1], $lineSize, ($product['product_reference'] != '' ? Tools::iconv('utf-8', self::encoding(), $product['product_reference']) : '---'), 'B');
			$this->Cell($w[2], $lineSize, $product['product_quantity'], 'LBR', 0, 'C');
			$this->Ln();
		}
	}

	/**
	* Main
	*
	* @param object $order Order
	* @param string $mode Download or display (optional)
	*/
	public static function invoice($order, $mode = 'D', $multiple = false, &$pdf = NULL, $slip = false, $delivery = false)
	{
	 	global $cookie;

		if (!Validate::isLoadedObject($order) OR (!$cookie->id_employee AND (!OrderState::invoiceAvailable($order->getCurrentState()) AND !$order->invoice_number)))
			die('Invalid order or invalid order state');
		self::$order = $order;
		self::$orderSlip = $slip;
		self::$delivery = $delivery;
		self::$_iso = strtoupper(Language::getIsoById((int)(self::$order->id_lang)));
		if ((self::$_priceDisplayMethod = $order->getTaxCalculationMethod()) === false)
			die(self::l('No price display method defined for the customer group'));

		if (!$multiple)
			$pdf = new PDF('P', 'mm', 'A4');

		$pdf->SetAutoPageBreak(true, 35);
		$pdf->StartPageGroup();

		self::$currency = Currency::getCurrencyInstance((int)(self::$order->id_currency));

		$pdf->AliasNbPages();
		$pdf->AddPage();
		/* Display address information */
		$invoice_address = new Address((int)($order->id_address_invoice));
		$invoiceState = $invoice_address->id_state ? new State($invoice_address->id_state) : false;
		$delivery_address = new Address((int)($order->id_address_delivery));
		$deliveryState = $delivery_address->id_state ? new State($delivery_address->id_state) : false;

		$width = 100;

		$pdf->SetX(10);
		$pdf->SetY(5);
		$pdf->SetFont(self::fontname(), 'B', 15);
		$pdf->SetFillColor(0, 0, 0); /* UNIQUE LINES ---------------------------------------------------------------*/
		$pdf->SetFont(self::fontname(), '', 7);
		$pdf->SetTextColor(240, 240, 240);
		$pdf->Cell(113, 7, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 7, self::l('Delivery'), 1, 0, 'L', 1);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->Ln(5);
		$pdf->SetFont(self::fontname(), 'B', 9);
		$pdf->Cell(113, 15, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 15, "", 'LR', 0, 'C');
		$pdf->Ln(5);
		$pdf->Cell(113, 10, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->firstname).' '.Tools::iconv('utf-8', self::encoding(), $delivery_address->lastname), 'LR', 0, 'C');
		$pdf->SetFont(self::fontname(), '', 9);
		$pdf->Ln(5);
		$pdf->Cell(113, 10, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->address1), 'LR', 0, 'C');
		$pdf->Ln(5);
		if (!empty($invoice_address->address2) OR !empty($delivery_address->address2))
		{
			$pdf->Cell(113, 10, '', '', 0, 'L', 0);
			$pdf->Cell(77, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->address2), 'LR', 0, 'C');
			$pdf->Ln(5);
		}
		$pdf->Cell(113, 10, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->city).' '.$delivery_address->postcode, 'LR', 0, 'C');
		$pdf->Ln(5);
		$pdf->Cell(113, 10, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 10, Tools::iconv('utf-8', self::encoding(), $delivery_address->country.($deliveryState ? ' - '.$deliveryState->name : '')), 'LR', 0, 'C');
		$pdf->Ln(5);
		$pdf->Cell(113, 15, "", 0, 0, 'L', 0);
		$pdf->Cell(77, 15, "", 'LBR', 0, 'C');
		$pdf->Ln(5);

		if (Configuration::get('VATNUMBER_MANAGEMENT') AND !empty($invoice_address->vat_number))
		{
			$vat_delivery = '';
			if ($invoice_address->id != $delivery_address->id)
				$vat_delivery = $delivery_address->vat_number;
			$pdf->Cell(77, 10, Tools::iconv('utf-8', self::encoding(), $vat_delivery), 0, 'L');
			$pdf->Ln(5);
		}

		if($invoice_address->dni != NULL)
			$pdf->Cell(77, 10, self::l('Tax ID number:').' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->dni), 0, 'L');

		/*
		 * display order information
		 */
		$pdf->SetFont(self::fontname(), 'B', 18);
		$carrier = new Carrier(self::$order->id_carrier);
		if ($carrier->name == '0')
				$carrier->name = Configuration::get('PS_SHOP_NAME');
		$history = self::$order->getHistory(self::$order->id_lang);
		foreach($history as $h)
			if ($h['id_order_state'] == _PS_OS_SHIPPING_)
				$shipping_date = $h['date_add'];
		$pdf->Ln(22);
		$pdf->Cell(0, 6, self::l('INVOICE #'), 0, 2, 'C');
		$pdf->Ln(4);
		$pdf->SetFont(self::fontname(), '', 7);
		$pdf->SetFillColor(0, 0, 0);
		$pdf->SetTextColor(240, 240, 240);
		if (self::$orderSlip)
			$pdf->Cell(0, 6, self::l('SLIP #').sprintf('%06d', self::$orderSlip->id).' '.self::l('from') . ' ' .Tools::displayDate(self::$orderSlip->date_upd, self::$order->id_lang), 1, 2, 'L', 1);
		elseif (self::$delivery)
			$pdf->Cell(0, 6, self::l('DELIVERY SLIP #').Configuration::get('PS_DELIVERY_PREFIX', (int)($cookie->id_lang)).sprintf('%06d', self::$delivery).' '.self::l('from') . ' ' .Tools::displayDate(self::$order->delivery_date, self::$order->id_lang), 1, 2, 'L', 1);
		else
			// normal slip DO NOT DELETE $pdf->Cell(0, 6, self::l('INVOICE #').Configuration::get('PS_INVOICE_PREFIX', (int)($cookie->id_lang)).sprintf('%06d', self::$order->invoice_number).' '.self::l('from') . ' ' .Tools::displayDate(self::$order->invoice_date, self::$order->id_lang), 1, 2, 'L', 1);
			$pdf->Cell(70, 6, self::l('Invoicing'), 'LBR', 0, 'L', 1);
			$pdf->Cell(30, 6, self::l('Tax ID number:'), 'LBR', 0, 'C', 1);
			$pdf->Cell(35, 6, self::l('Order #'), 'LBR', 0, 'C', 1);
			$pdf->Cell(30, 6, self::l('Payment method:'), 'LBR', 0, 'C', 1);
			$pdf->Cell(25, 6, self::l('Shipping date:'), 'LBR', 0, 'C', 1);
		$pdf->Ln(6);
		/*$pdf->MultiCell(70, 20, Tools::iconv('utf-8', self::encoding(), $invoice_address->company)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->lastname).' '
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->firstname)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->address1)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->address2)
		.$invoice_address->postcode.' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->city)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->country.($invoiceState ? ' - '.$invoiceState->name : '')). 'LR', 0, 'L');*/
		// $pdf->MultiCell(70, 20, Tools::iconv('utf-8', self::encoding(), $invoice_address->company), 'LR', 0, 'L');
		$pdf->SetTextColor(0, 0, 0);
		$pdf->Cell(70, 20, Tools::iconv('utf-8', self::encoding(), $invoice_address->firstname).' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->lastname)/*.' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->address1).Tools::iconv('utf-8', self::encoding(), $invoice_address->address2).$invoice_address->postcode.' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->city).' '.$invoice_address->postcode.' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->country.($invoiceState ? ' - '.$invoiceState->name : ''))*/, 'LBR', 0, 'L');
		/*.Tools::iconv('utf-8', self::encoding(), $invoice_address->firstname)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->address1)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->address2)
		.$invoice_address->postcode.' '.Tools::iconv('utf-8', self::encoding(), $invoice_address->city)
		.Tools::iconv('utf-8', self::encoding(), $invoice_address->country.($invoiceState ? ' - '.$invoiceState->name : '')), 'LR', 0, 'L');*/
		
		$pdf->Cell(30, 20, self::l(sprintf('%06d', self::$order->invoice_number)), 'LBR', 0, 'C');
		$pdf->Cell(35, 20, self::l(sprintf('%06d', self::$order->id)), 'LBR', 0, 'C');
		$pdf->Cell(30, 20, Tools::iconv('utf-8', self::encoding(), $order->payment), 'LBR', 0, 'C');
		$pdf->Cell(25, 20, self::l(Tools::displayDate(self::$order->invoice_date, self::$order->id_lang)), 'LBR', 2, 'C', 0);
		$pdf->Ln(5);
		$pdf->ProdTab((self::$delivery ? true : ''));

		/* Exit if delivery */
		if (!self::$delivery)
		{
			if (!self::$orderSlip)
				$pdf->DiscTab();
			$priceBreakDown = array();
			$pdf->priceBreakDownCalculation($priceBreakDown);

			if (!self::$orderSlip OR (self::$orderSlip AND self::$orderSlip->shipping_cost))
			{
				$priceBreakDown['totalWithoutTax'] += Tools::ps_round($priceBreakDown['shippingCostWithoutTax'], 2) + Tools::ps_round($priceBreakDown['wrappingCostWithoutTax'], 2);
				$priceBreakDown['totalWithTax'] += self::$order->total_shipping + self::$order->total_wrapping;
			}
			if (!self::$orderSlip)
			{
				$taxDiscount = self::$order->getTaxesAverageUsed();
				if ($taxDiscount != 0)
					$priceBreakDown['totalWithoutTax'] -= Tools::ps_round(self::$order->total_discounts / (1 + self::$order->getTaxesAverageUsed() * 0.01), 2);
				else
					$priceBreakDown['totalWithoutTax'] -= self::$order->total_discounts;
				$priceBreakDown['totalWithTax'] -= self::$order->total_discounts;
			}

			/*
			 * Display price summation
			 */
			if (self::$order->total_shipping != '0.00' AND (!self::$orderSlip OR (self::$orderSlip AND self::$orderSlip->shipping_cost)))
			{
				/*$pdf->Ln(0);
				$pdf->SetFillColor(240, 240, 240);
				$pdf->Cell(100, 6, self::l('Total shipping'), 'LB', 0, 'L', 1);
				if (self::$_priceDisplayMethod == PS_TAX_EXC)
					$pdf->Cell(90, 6, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(Tools::ps_round($priceBreakDown['shippingCostWithoutTax'], 2), self::$currency, true, false)), 'BR', 0, 'R', 1);
				else
					$pdf->Cell(90, 6, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_shipping, self::$currency, true, false)), 'BR', 0, 'R', 1);
				$pdf->Ln(6); // SHIPPING LINE */
			} 
			
			if (!self::$orderSlip AND self::$order->total_discounts != '0.00')
			{
				$pdf->Cell($width, 0, self::l('Total discounts (tax incl.)').' : ', 0, 0, 'R');
				//$pdf->Cell(0, 0, (!self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_discounts, self::$currency, true, false)), 0, 0, 'R');
				$pdf->Ln(6);
			}

			if(isset(self::$order->total_wrapping) and ((float)(self::$order->total_wrapping) > 0))
			{
				$pdf->Cell($width, 0, self::l('Total gift-wrapping').' : ', 0, 0, 'R');
				if (self::$_priceDisplayMethod == PS_TAX_EXC)
					$pdf->Cell(0, 0, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['wrappingCostWithoutTax'], self::$currency, true, false)), 0, 0, 'R');
				else
					$pdf->Cell(0, 0, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_wrapping, self::$currency, true, false)), 0, 0, 'R');
				$pdf->Ln(10);
			}

			if (Configuration::get('PS_TAX') OR $order->total_products_wt != $order->total_products)
			{
				/*$pdf->SetFillColor(100, 100, 100);
				$pdf->SetTextColor(240, 240, 240);
				$pdf->Cell(130, 10, "", 0, 0, 'R');
				$pdf->Cell(35, 10, self::l('Total Tax').' : ', 'LBR', 0, 'C', 1);
				$pdf->SetTextColor(0, 0, 0);
				/*$tgst = Tools::ps_round($priceBreakDown['shippingCostWithTax'], 2) - Tools::ps_round($priceBreakDown['shippingCostWithoutTax'], 2);
				$tgst = self::$order->total_shipping - Tools::ps_round($priceBreakDown['shippingCostWithoutTax'], 2);
				$tgst += $priceBreakDown['totalWithTax'] - $priceBreakDown['totalWithoutTax'];
				$pdf->Cell(25, 10, self::convertSign(Tools::displayPrice($tgst, self::$currency, true, false)), 'LBR', 0, 'R');
				/*$pdf->Cell(25, 10, $tgst, 'LBR', 0, 'R');
				$pdf->Ln(10);
				$pdf->SetFillColor(0, 0, 0);
				$pdf->SetTextColor(240, 240, 240);
				$pdf->Cell(130, 10, "", 0, 0, 'R');
				$pdf->Cell(35, 10, (self::$_priceDisplayMethod == PS_TAX_EXC ? self::l(' (tax excl.)') : self::l(' (tax incl.)')).' : ', 'LBR', 0, 'C', 1);
				$pdf->SetTextColor(0, 0, 0);
				$pdf->Cell(25, 10, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice((self::$_priceDisplayMethod == PS_TAX_EXC ? $priceBreakDown['totalWithoutTax'] : $priceBreakDown['totalWithTax']), self::$currency, true, false)), 'LBR', 0, 'R');
				$pdf->Ln(4); // CHARGES LINE */
			}
			else
			{
				$pdf->Cell(100, 10, self::l('Total').' : ', 0, 0, 'R', 1);
				$pdf->Cell(90, 10, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(($priceBreakDown['totalWithoutTax']), self::$currency, true, false)), 0, 0, 'R', 1);
				$pdf->Ln(4);
			}

			$pdf->TaxTab($priceBreakDown);
		}
		Hook::PDFInvoice($pdf, self::$order->id);

		if (!$multiple)
			return $pdf->Output(sprintf('%06d', self::$order->id).'.pdf', $mode);
	}

	public function ProdTabHeader($delivery = false)
	{
		if (!$delivery)
		{
			$header = array(
				array(self::l('Description'), 'L'),
				array(self::l('Reference'), 'C'),
				array(self::l('U. price'), 'C'),
				array(self::l('Qty'), 'C'),
				array(self::l('Total'), 'C')
			);
			$w = array(70, 35, 20, 25, 40);
		}
		else
		{
			$header = array(
				array(self::l('Description'), 'L'),
				array(self::l('Reference'), 'C'),
				array(self::l('Qty'), 'C'),
			);
			$w = array(120, 30, 10);
		}
		$this->SetFont(self::fontname(), 'B', 8);
		$this->SetFillColor(0, 0, 0);
		$this->SetTextColor(240, 240, 240);
		if ($delivery)
			$this->SetX(25);
		for($i = 0; $i < sizeof($header); $i++)
			$this->Cell($w[$i], 5, $header[$i][0], 'T', 0, $header[$i][1], 1);
		$this->Ln();
		$this->SetFont(self::fontname(), '', 8);
	}

	/**
	* Product table with price, quantities...
	*/
	public function ProdTab($delivery = false)
	{
		if (!$delivery)
			$w = array(70, 35, 20, 25, 40);
		else
			$w = array(120, 30, 10);
		self::ProdTabHeader($delivery);
		if (!self::$orderSlip)
		{
			if (isset(self::$order->products) AND sizeof(self::$order->products))
				$products = self::$order->products;
			else
				$products = self::$order->getProducts();
		}
		else
			$products = self::$orderSlip->getProducts();
		$customizedDatas = Product::getAllCustomizedDatas((int)(self::$order->id_cart));
		Product::addCustomizationPrice($products, $customizedDatas);

		$counter = 0;
		$lines = 25;
		$lineSize = 0;
		$line = 0;


		foreach($products AS $product)
			if (!$delivery OR ((int)($product['product_quantity']) - (int)($product['product_quantity_refunded']) > 0))
			{
				if($counter >= $lines)
				{
					$this->AddPage();
					$this->Ln();
					self::ProdTabHeader($delivery);
					$lineSize = 0;
					$counter = 0;
					$lines = 40;
					$line++;
				}
				$counter = $counter + ($lineSize / 5) ;

				$i = -1;

				// Unit vars
				$this->SetTextColor(0, 0, 0);
				$unit_without_tax = $product['product_price'] + $product['ecotax'];
				$unit_with_tax = $product['product_price_wt'] + ($product['ecotax'] * (1 + $product['ecotax_tax_rate'] / 100));
				if (self::$_priceDisplayMethod == PS_TAX_EXC)
					$unit_price = &$unit_without_tax;
				else
					$unit_price = &$unit_with_tax;
				$productQuantity = $delivery ? ((int)($product['product_quantity']) - (int)($product['product_quantity_refunded'])) : (int)($product['product_quantity']);

				if ($productQuantity <= 0)
					continue ;

				// Total prices
				$total_with_tax = $unit_with_tax * $productQuantity;
				$total_without_tax = $unit_without_tax * $productQuantity;
				// Spec
				if (self::$_priceDisplayMethod == PS_TAX_EXC)
					$final_price = &$total_without_tax;
				else
					$final_price = &$total_with_tax;
				// End Spec

				if (isset($customizedDatas[$product['product_id']][$product['product_attribute_id']]))
				{
					$productQuantity = (int)($product['product_quantity']) - (int)($product['customizationQuantityTotal']);
					if ($delivery)
						$this->SetX(25);
					$before = $this->GetY();
					$this->MultiCell($w[++$i], 5, Tools::iconv('utf-8', self::encoding(), $product['product_name']).' - '.self::l('Customized'), 'LBR', 0, 'L', 0);
					$lineSize = $this->GetY() - $before;
					$this->SetXY($this->GetX() + $w[0] + ($delivery ? 15 : 0), $this->GetY() - $lineSize);
					$this->Cell($w[++$i], $lineSize, $product['product_reference'], 'LBR', 0, 1, 'C');
					if (!$delivery)
						$this->Cell($w[++$i], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($unit_price, self::$currency, true, false)), 'B', 0, 'C');
					$this->Cell($w[++$i], $lineSize, (int)($product['customizationQuantityTotal']), 'B', 0, 'C');
					if (!$delivery)
						$this->Cell($w[++$i], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($unit_price * (int)($product['customizationQuantityTotal']), self::$currency, true, false)), 'B', 0, 'C');
					$this->Ln();
					$i = -1;
					$total_with_tax = $unit_with_tax * $productQuantity;
					$total_without_tax = $unit_without_tax * $productQuantity;
				}
				if ($delivery)
					$this->SetX(25);
				if ($productQuantity)
				{
					$before = $this->GetY();
					$this->MultiCell($w[++$i], 5, Tools::iconv('utf-8', self::encoding(), $product['product_name']), 'LBR'); // HERRRRRRRRRRRRRRRREERERERRFWEDSFWAFWEFWEFWEF
					$lineSize = $this->GetY() - $before;
					$this->SetXY($this->GetX() + $w[0] + ($delivery ? 15 : 0), $this->GetY() - $lineSize);
					$this->Cell($w[++$i], $lineSize, ($product['product_reference'] ? $product['product_reference'] : '--'), 'LBR', 0, 'C');
					if (!$delivery)
						$this->Cell($w[++$i], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($unit_price, self::$currency, true, false)), 'LBR', 0, 'C');
					$this->Cell($w[++$i], $lineSize, $productQuantity, 'LBR', 0, 'C');
					if (!$delivery)
						$this->Cell($w[++$i], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($final_price, self::$currency, true, false)), 'LBR', 0, 'C');
					$this->Ln();
				}
			}

		if (!sizeof(self::$order->getDiscounts()) AND !$delivery)
			$this->Cell(array_sum($w), 0, '');
	}

	/**
	* Discount table with value, quantities...
	*/
	public function DiscTab()
	{
		$w = array(90, 25, 15, 10, 25, 25);
		$this->SetFont(self::fontname(), 'B', 7);
		$this->SetFillColor(240, 240, 240);
		$discounts = self::$order->getDiscounts();

		foreach($discounts AS $discount)
		{
			$this->Cell($w[0], 6, self::l('Discount:').' '.$discount['name'], 'LB', 0, 'L', 1);
			$this->Cell($w[1], 6, '', 'B', 0, 'L', 1);
			$this->Cell($w[2], 6, '', 'B', 0, 'L', 1);
			$this->Cell($w[3], 6, '', 'B', 0, 'R', 1);
			$this->Cell($w[4], 6, '1', 'B', 0, 'C', 1);
			$this->Cell($w[5], 6, ((!self::$orderSlip AND $discount['value'] != 0.00) ? '-' : '').self::convertSign(Tools::displayPrice($discount['value'], self::$currency, true, false)), 'RB', 0, 'R', 1);
			//$this->Ln();
		}

		/*if (sizeof($discounts))
			$this->Cell(array_sum($w), 0, '');*/
	}

	public function priceBreakDownCalculation(array &$priceBreakDown)
	{
		if (!$id_zone = Address::getZoneById(intval(self::$order->id_address_invoice)))
			die(Tools::displayError());
		$priceBreakDown['totalsWithoutTax'] = array();
		$priceBreakDown['totalsWithTax'] = array();
		$priceBreakDown['totalsEcotax'] = array();
		$priceBreakDown['wrappingCostWithoutTax'] = 0;
		$priceBreakDown['shippingCostWithoutTax'] = 0;
		$priceBreakDown['totalWithoutTax'] = 0;
		$priceBreakDown['totalWithTax'] = 0;
		$priceBreakDown['totalProductsWithoutTax'] = 0;
		$priceBreakDown['totalProductsWithTax'] = 0;
		$priceBreakDown['hasEcotax'] = 0;
		if (self::$order->total_paid == '0.00' AND self::$order->total_discounts == 0)
			return ;

		// Setting products tax
		if (isset(self::$order->products) AND sizeof(self::$order->products))
			$products = self::$order->products;
		else
			$products = self::$order->getProducts();
		$amountWithoutTax = 0;
		$taxes = array();
		/* Firstly calculate all prices */
		foreach ($products AS &$product)
		{
			if (!isset($priceBreakDown['totalsWithTax'][$product['tax_rate']]))
				$priceBreakDown['totalsWithTax'][$product['tax_rate']] = 0;
			if (!isset($priceBreakDown['totalsEcotax'][$product['tax_rate']]))
				$priceBreakDown['totalsEcotax'][$product['tax_rate']] = 0;
			if (!isset($priceBreakDown['totalsWithoutTax'][$product['tax_rate']]))
				$priceBreakDown['totalsWithoutTax'][$product['tax_rate']] = 0;
			if (!isset($taxes[$product['tax_rate']]))
				$taxes[$product['tax_rate']] = 0;
			/* Without tax */
			$product['priceWithoutTax'] = Tools::ps_round(self::$_priceDisplayMethod == PS_TAX_EXC ? floatval($product['product_price']) : $product['product_price_wt_but_ecotax'] / (1 + $product['tax_rate'] / 100), 2) * (int)($product['product_quantity']);
			$amountWithoutTax += $product['priceWithoutTax'];
			/* With tax */
			$product['priceWithTax'] = floatval($product['product_price_wt']) * intval($product['product_quantity']);
			$product['priceEcotax'] = $product['ecotax'] * (1 + $product['ecotax_tax_rate'] / 100);
		}

		$priceBreakDown['totalsProductsWithoutTax'] = $priceBreakDown['totalsWithoutTax'];
		$priceBreakDown['totalsProductsWithTax'] = $priceBreakDown['totalsWithTax'];

		$tmp = 0;
		$product = &$tmp;
		/* And secondly assign to each tax its own reduction part */
		$discountAmount = floatval(self::$order->total_discounts);
		foreach ($products as $product)
		{
			$ratio = $amountWithoutTax == 0 ? 0 : $product['priceWithoutTax'] / $amountWithoutTax;
			$priceWithTaxAndReduction = $product['priceWithTax'] - $discountAmount * $ratio;
			if (self::$_priceDisplayMethod == PS_TAX_EXC)
			{
				$vat = $priceWithTaxAndReduction - Tools::ps_round($priceWithTaxAndReduction / $product['product_quantity'] / ((floatval($product['tax_rate']) / 100) + 1), 2) * $product['product_quantity'];
				$priceBreakDown['totalsWithoutTax'][$product['tax_rate']] += $product['priceWithoutTax'];
				$priceBreakDown['totalsProductsWithoutTax'][$product['tax_rate']] += $product['priceWithoutTax'];
			}
			else
			{
				$vat = floatval($product['priceWithoutTax']) * (floatval($product['tax_rate'])  / 100) * $product['product_quantity'];
				$priceBreakDown['totalsWithTax'][$product['tax_rate']] += $product['priceWithTax'];
				$priceBreakDown['totalsProductsWithTax'][$product['tax_rate']] += $product['priceWithTax'];
				$priceBreakDown['totalsProductsWithoutTax'][$product['tax_rate']] += $product['priceWithoutTax'];
			}
			$priceBreakDown['totalsEcotax'][$product['tax_rate']] += ($product['priceEcotax']  * $product['product_quantity']);
			if ($priceBreakDown['totalsEcotax'][$product['tax_rate']])
				$priceBreakDown['hasEcotax'] = 1;
			$taxes[$product['tax_rate']] += $vat;
		}
		$carrier = new Carrier(self::$order->id_carrier);
		$carrierTax = new Tax($carrier->id_tax);
		if (($priceBreakDown['totalsWithoutTax'] == $priceBreakDown['totalsWithTax']) AND (!$carrierTax->rate OR $carrierTax->rate == '0.00') AND (!self::$order->total_wrapping OR self::$order->total_wrapping == '0.00'))
			return ;

		// Display product tax
		foreach ($taxes AS $tax_rate => &$vat)
		{
			if (self::$_priceDisplayMethod == PS_TAX_EXC)
			{
				$priceBreakDown['totalsWithoutTax'][$tax_rate] = Tools::ps_round($priceBreakDown['totalsWithoutTax'][$tax_rate], 2);
				$priceBreakDown['totalsProductsWithoutTax'][$tax_rate] = Tools::ps_round($priceBreakDown['totalsWithoutTax'][$tax_rate], 2);
				$priceBreakDown['totalsWithTax'][$tax_rate] = Tools::ps_round($priceBreakDown['totalsWithoutTax'][$tax_rate] * (1 + $tax_rate / 100), 2) + $priceBreakDown['totalsEcotax'][$tax_rate];
				$priceBreakDown['totalsProductsWithTax'][$tax_rate] = Tools::ps_round($priceBreakDown['totalsProductsWithoutTax'][$tax_rate] * (1 + $tax_rate / 100), 2) + $priceBreakDown['totalsEcotax'][$tax_rate];
			}
			else
			{
				$priceBreakDown['totalsWithoutTax'][$tax_rate] = $priceBreakDown['totalsProductsWithoutTax'][$tax_rate];
				$priceBreakDown['totalsProductsWithoutTax'][$tax_rate] = Tools::ps_round($priceBreakDown['totalsProductsWithoutTax'][$tax_rate], 2);
			}
			$priceBreakDown['totalWithTax'] += $priceBreakDown['totalsWithTax'][$tax_rate];
			$priceBreakDown['totalWithoutTax'] += $priceBreakDown['totalsWithoutTax'][$tax_rate];
			$priceBreakDown['totalProductsWithoutTax'] += $priceBreakDown['totalsProductsWithoutTax'][$tax_rate];
			$priceBreakDown['totalProductsWithTax'] += $priceBreakDown['totalsProductsWithTax'][$tax_rate];
		}
		$priceBreakDown['taxes'] = $taxes;
		$priceBreakDown['shippingCostWithoutTax'] = ($carrierTax->rate AND $carrierTax->rate != '0.00' AND self::$order->total_shipping != '0.00' AND Tax::zoneHasTax(intval($carrier->id_tax), intval($id_zone))) ? (!Configuration::get('PS_TAX') ? self::$order->total_shipping : (self::$order->total_shipping / (1 + ($carrierTax->rate / 100)))) : self::$order->total_shipping;
		if (self::$order->total_wrapping AND self::$order->total_wrapping != '0.00')
		{
			$wrappingTax = new Tax(Configuration::get('PS_GIFT_WRAPPING_TAX'));
			$priceBreakDown['wrappingCostWithoutTax'] = self::$order->total_wrapping / (1 + (floatval($wrappingTax->rate) / 100));
		}
	}

	/**
	* Tax table
	*/
	public function TaxTab(array &$priceBreakDown)
	{
		if (!$id_zone = Address::getZoneById(intval(self::$order->id_address_invoice)))
			die(Tools::displayError());

		if (self::$order->total_paid == '0.00' OR (!intval(Configuration::get('PS_TAX')) AND self::$order->total_products == self::$order->total_products_wt))
			return ;

		// Setting products tax
		if (isset(self::$order->products) AND sizeof(self::$order->products))
			$products = self::$order->products;
		else
			$products = self::$order->getProducts();

		$carrier = new Carrier(self::$order->id_carrier);
		$carrierTax = new Tax($carrier->id_tax);
		if (($priceBreakDown['totalsWithoutTax'] == $priceBreakDown['totalsWithTax']) AND (!$carrierTax->rate OR $carrierTax->rate == '0.00') AND (!self::$order->total_wrapping OR self::$order->total_wrapping == '0.00'))
			return ;
			
		$this->Ln(0);
		$this->SetFillColor(240, 240, 240);
		$this->Cell(100, 6, self::l('Total shipping'), 'LB', 0, 'L', 1);
		if (self::$_priceDisplayMethod == PS_TAX_EXC)
			$this->Cell(90, 6, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(Tools::ps_round($priceBreakDown['shippingCostWithoutTax'], 2), self::$currency, true, false)), 'BR', 0, 'R', 1);
		else
			$this->Cell(90, 6, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_shipping, self::$currency, true, false)), 'BR', 0, 'R', 1);
			$this->Ln(6);
				
		$this->SetFillColor(100, 100, 100);
		$this->SetTextColor(240, 240, 240);
		$this->Cell(130, 10, "", 0, 0, 'R');
		$this->Cell(35, 10, self::l('Total Tax').' : ', 'LBR', 0, 'C', 1);
		$this->SetTextColor(0, 0, 0);
		/*$tgst = Tools::ps_round($priceBreakDown['shippingCostWithTax'], 2) - Tools::ps_round($priceBreakDown['shippingCostWithoutTax'], 2);*/
		$tgst = $priceBreakDown['totalWithTax'] / 11;
		$this->Cell(25, 10, self::convertSign(Tools::displayPrice(Tools::ps_round($tgst, 2), self::$currency, true, false)), 'LBR', 0, 'R');
		/*$pdf->Cell(25, 10, $tgst, 'LBR', 0, 'R');*/
		$this->Ln(10);
		$this->SetFillColor(0, 0, 0);
		$this->SetTextColor(240, 240, 240);
		$this->Cell(130, 10, "", 0, 0, 'R');
		$this->Cell(35, 10, (self::$_priceDisplayMethod == PS_TAX_EXC ? self::l(' (tax excl.)') : self::l(' (tax incl.)')).' : ', 'LBR', 0, 'C', 1);
		$this->SetTextColor(0, 0, 0);
		$this->Cell(25, 10, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice((self::$_priceDisplayMethod == PS_TAX_EXC ? $priceBreakDown['totalWithoutTax'] : $priceBreakDown['totalWithTax']), self::$currency, true, false)), 'LBR', 0, 'R');
		$this->Ln(4);
		// Displaying header tax DONT TO AVOID GLITCH
		/*
		if ($priceBreakDown['hasEcotax'])
		{
			$header = array(self::l('Tax detail'), self::l('Tax'), self::l('Pre-Tax Total'), self::l('Total Tax'), self::l('Ecotax'), self::l('Total with Tax'));
			$w = array(60, 30, 40, 20, 20, 20);
		}
		else
		{
			$header = array(self::l('Tax detail'), self::l('Tax'), self::l('Pre-Tax Total'), self::l('Total Tax'), self::l('Total with Tax'));
			$w = array(60, 30, 40, 30, 30);
		}
		$this->SetFont(self::fontname(), 'B', 8);
		for($i = 0; $i < sizeof($header); $i++)
			$this->Cell($w[$i], 5, $header[$i], 0, 0, 'R');

		$this->Ln();
		$this->SetFont(self::fontname(), '', 7);

		$nb_tax = 0;
		$total = 0;
		// Display product tax
		foreach ($priceBreakDown['taxes'] AS $tax_rate => $vat)
		{
			if ($tax_rate != '0.00' AND $priceBreakDown['totalsProductsWithTax'][$tax_rate] != '0.00')
			{
				$nb_tax++;
				$before = $this->GetY();
				$lineSize = $this->GetY() - $before;
				$this->SetXY($this->GetX(), $this->GetY() - $lineSize + 3);
				$this->Cell($w[0], $lineSize, self::l('Products'), 0, 0, 'R');
				$this->Cell($w[1], $lineSize, number_format($tax_rate, 3, ',', ' ').' %', 0, 0, 'R');
				$this->Cell($w[2], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['totalsProductsWithoutTax'][$tax_rate], self::$currency, true, false)), 0, 0, 'R');
				$this->Cell($w[3], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['totalsProductsWithTax'][$tax_rate] - $priceBreakDown['totalsProductsWithoutTax'][$tax_rate] - $priceBreakDown['totalsEcotax'][$tax_rate], self::$currency, true, false)), 0, 0, 'R');
				if ($priceBreakDown['hasEcotax'])
					$this->Cell($w[4], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['totalsEcotax'][$tax_rate], self::$currency, true, false)), 0, 0, 'R');
				$this->Cell($w[$priceBreakDown['hasEcotax'] ? 5 : 4], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['totalsProductsWithTax'][$tax_rate], self::$currency, true, false)), 0, 0, 'R');
				$this->Ln();
			}
		}

		// Display carrier tax
		if ($carrierTax->rate AND $carrierTax->rate != '0.00' AND ((self::$order->total_shipping != '0.00' AND !self::$orderSlip) OR (self::$orderSlip AND self::$orderSlip->shipping_cost)) AND Tax::zoneHasTax(intval($carrier->id_tax), intval($id_zone)))
		{
			$nb_tax++;
			$before = $this->GetY();
			$lineSize = $this->GetY() - $before;
			$this->SetXY($this->GetX(), $this->GetY() - $lineSize + 3);
			$this->Cell($w[0], $lineSize, self::l('Carrier'), 0, 0, 'R');
			$this->Cell($w[1], $lineSize, number_format($carrierTax->rate, 3, ',', ' ').' %', 0, 0, 'R');
			$this->Cell($w[2], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['shippingCostWithoutTax'], self::$currency, true, false)), 0, 0, 'R');
			$this->Cell($w[3], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_shipping - $priceBreakDown['shippingCostWithoutTax'], self::$currency, true, false)), 0, 0, 'R');
			if ($priceBreakDown['hasEcotax'])
				$this->Cell($w[4], $lineSize, (self::$orderSlip ? '-' : '').'', 0, 0, 'R');
			$this->Cell($w[$priceBreakDown['hasEcotax'] ? 5 : 4], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_shipping, self::$currency, true, false)), 0, 0, 'R');
			$this->Ln();
		}

		// Display wrapping tax
		if (self::$order->total_wrapping AND self::$order->total_wrapping != '0.00')
		{
			$tax = new Tax(intval(Configuration::get('PS_GIFT_WRAPPING_TAX')));
			$taxRate = $tax->rate;
			
			$nb_tax++;
			$before = $this->GetY();
			$lineSize = $this->GetY() - $before;
			$this->SetXY($this->GetX(), $this->GetY() - $lineSize + 3);
			$this->Cell($w[0], $lineSize, self::l('Wrapping'), 0, 0, 'R');
			$this->Cell($w[1], $lineSize, number_format($taxRate, 3, ',', ' ').' %', 0, 0, 'R');
			$this->Cell($w[2], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice($priceBreakDown['wrappingCostWithoutTax'], self::$currency, true, false)), 0, 0, 'R');
			$this->Cell($w[3], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_wrapping - $priceBreakDown['wrappingCostWithoutTax'], self::$currency, true, false)), 0, 0, 'R');
			$this->Cell($w[4], $lineSize, (self::$orderSlip ? '-' : '').self::convertSign(Tools::displayPrice(self::$order->total_wrapping, self::$currency, true, false)), 0, 0, 'R');
		}

		if (!$nb_tax)
			$this->Cell(190, 10, self::l('No tax'), 0, 0, 'C');*/
	}

	static private function convertSign($s)
	{
		return str_replace('¥', chr(165), str_replace('£', chr(163), str_replace('€', chr(128), $s)));
	}

	static protected function l($string)
	{
		global $cookie;
		$iso = Language::getIsoById((isset($cookie->id_lang) AND Validate::isUnsignedId($cookie->id_lang)) ? $cookie->id_lang : Configuration::get('PS_LANG_DEFAULT'));
		
		if (@!include(_PS_TRANSLATIONS_DIR_.$iso.'/pdf.php'))
			die('Cannot include PDF translation language file : '._PS_TRANSLATIONS_DIR_.$iso.'/pdf.php');

		if (!is_array($_LANGPDF))
			return str_replace('"', '&quot;', $string);
		$key = md5(str_replace('\'', '\\\'', $string));
		$str = (key_exists('PDF_invoice'.$key, $_LANGPDF) ? $_LANGPDF['PDF_invoice'.$key] : $string);

		return (Tools::iconv('utf-8', self::encoding(), $str));
	}

	static private function encoding()
	{
		return (isset(self::$_pdfparams[self::$_iso]) AND is_array(self::$_pdfparams[self::$_iso]) AND self::$_pdfparams[self::$_iso]['encoding']) ? self::$_pdfparams[self::$_iso]['encoding'] : 'iso-8859-1';
	}

	static private function embedfont()
	{
		return (((isset(self::$_pdfparams[self::$_iso]) AND is_array(self::$_pdfparams[self::$_iso]) AND self::$_pdfparams[self::$_iso]['font']) AND !in_array(self::$_pdfparams[self::$_iso]['font'], self::$_fpdf_core_fonts)) ? self::$_pdfparams[self::$_iso]['font'] : false);
	}

	static private function fontname()
	{
		$font = self::embedfont();
		if (in_array(self::$_pdfparams[self::$_iso]['font'], self::$_fpdf_core_fonts))
			$font = self::$_pdfparams[self::$_iso]['font'];
		return $font ? $font : 'Arial';
 	}

}
