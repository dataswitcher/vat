<?php
declare(strict_types=1);

namespace Ibericode\Vat;

class Validator
{

    /**
     * Regular expression patterns per country code
     *
     * @var array
     * @link http://ec.europa.eu/taxation_customs/vies/faq.html?locale=lt#item_11
     */
    private $patterns = [
        'AT' => 'U[A-Z\d]{8}',
        'BE' => '(0\d{9}|\d{10})',
        'BG' => '\d{9,10}',
        'CY' => '\d{8}[A-Z]',
        'CZ' => '\d{8,10}',
        'DE' => '\d{9}',
        'DK' => '(\d{2} ?){3}\d{2}',
        'EE' => '\d{9}',
        'EL' => '\d{9}',
        'ES' => '([A-Z]\d{7}[A-Z]|\d{8}[A-Z]|[A-Z]\d{8})',
        'FI' => '\d{8}',
        'FR' => '[A-Z\d]{2}\d{9}',
        'GB' => '(\d{9}|\d{12}|(GD|HA)\d{3})',
        'HR' => '\d{11}',
        'HU' => '\d{8}',
        'IE' => '([A-Z\d]{8}|[A-Z\d]{9})',
        'IT' => '\d{11}',
        'LT' => '(\d{9}|\d{12})',
        'LU' => '\d{8}',
        'LV' => '\d{11}',
        'MT' => '\d{8}',
        'NL' => '\d{9}B\d{2}',
        'PL' => '\d{10}',
        'PT' => '\d{9}',
        'RO' => '\d{2,10}',
        'SE' => '\d{12}',
        'SI' => '\d{8}',
        'SK' => '\d{10}',
        'XI' => '(\d{9}|\d{12}|(GD|HA)\d{3})'
    ];

    private $modulusCheckCallback = [
        'BE' => 'localBEValidation',
        'LU' => 'localLUValidation',
        'DE' => 'localDEValidation',
        'NL' => 'localNLValidation',
        'ES' => '',
        'FR' => 'localFRValidation',
        'GB' => '',
        'XI' => '',
    ];

    /**
     * @var Vies\Client
     */
    private $client;

    /**
     * VatValidator constructor.
     *
     * @param Vies\Client $client        (optional)
     */
    public function __construct(Vies\Client $client = null)
    {
        $this->client = $client ?: new Vies\Client();
    }

    /**
     * Checks whether the given string is a valid ISO-3166-1-alpha2 country code
     *
     * @param string $countryCode
     * @return bool
     */
    public function validateCountryCode(string $countryCode) : bool
    {
        $countries = new Countries();
        return isset($countries[$countryCode]);
    }

    /**
     * Checks whether the given string is a valid public IPv4 or IPv6 address
     *
     * @param string $ipAddress
     * @return bool
     */
    public function validateIpAddress(string $ipAddress) : bool
    {
        if ($ipAddress === '') {
            return false;
        }

        return (bool) filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
    }

    /**
     * Validate a VAT number format. This does not check whether the VAT number was really issued.
     *
     * @param string $vatNumber
     *
     * @return boolean
     */
    public function validateVatNumberFormat(string $vatNumber) : bool
    {
        if ($vatNumber === '') {
            return false;
        }

        $vatNumber = strtoupper($vatNumber);
        $country = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);

        if (! isset($this->patterns[$country])) {
            return false;
        }

        return preg_match('/^' . $this->patterns[$country] . '$/', $number) > 0;
    }

    /**
     *
     * @param string $vatNumber
     *
     * @return boolean
     *
     * @throws Vies\ViesException
     */
    protected function validateVatNumberExistence(string $vatNumber) : bool
    {
        $vatNumber = strtoupper($vatNumber);
        $country = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);
        return $this->client->checkVat($country, $number);
    }

    /**
     * Validates a VAT number using format + existence check.
     *
     * @param string $vatNumber Either the full VAT number (incl. country) or just the part after the country code.
     *
     * @return boolean
     *
     * @throws Vies\ViesException
     */
    public function validateVatNumber(string $vatNumber, bool $local = false) : bool
    {
        if ($local) {
            return $this->validateVatNumberFormat($vatNumber) && $this->validateVatNumberModulus($vatNumber);
        }
        return $this->validateVatNumberFormat($vatNumber) && $this->validateVatNumberExistence($vatNumber);
    }

    private function validateVatNumberModulus(string $vatNumber)
    {
        $vatNumber = strtoupper($vatNumber);
        $country = substr($vatNumber, 0, 2);

        if (isset($this->modulusCheckCallback[$country])) {
            return call_user_func(array($this, $this->modulusCheckCallback[$country]), $vatNumber);
        }

        // returning true because we don't want it to fail if there is no modulus check available
        return true;
    }

    /**
     * Validates a belgium VAT number
     * Account Invalid VAT number.\nThe number should be entered in the format: BE0999999999 - 1 block of 10 digits.
     * (The first digit following the prefix is always zero ('0').
     * The (new) 10-digit format is the result of adding a leading zero to the (old) 9-digit format.)
     * (Example: BE0000000097)
     * @url https://help.afas.nl/help/NL/SE/Fin_Config_VatIct_NrChck.htm
     * @param $vat_number string the vat number
     * @return bool
     */
    private function localBEValidation($vat_number)
    {
        if (substr($vat_number, 0, 3) !== 'BE0') {
            return false;
        }
        $number = (int)substr($vat_number, 2, 8);
        $check = (int)substr($vat_number, 10, 2);
        $rest = 97 - ($number % 97);

        return $rest === $check;
    }

    private function localLUValidation($vat_number)
    {
        if (substr($vat_number, 0, 2) !== 'LU') {
            return false;
        }
        $number = (int)substr($vat_number, 2, 6);
        $check = (int)substr($vat_number, 8, 2);
        $rest = $number % 89;

        return $rest === $check;
    }

    private function localFRValidation($vat_number)
    {
        if (substr($vat_number, 0, 2) !== 'FR') {
            return false;
        }

        // We cannot validate this without the first two check numbers
        if(!preg_match('/^\d{2}$/', substr($vat_number, 2, 2))) {
            return true;
        }

        $number = (int)substr($vat_number, 4, 11);
        $check = (int)substr($vat_number, 2, 2);
        $rest = (($number * 100) + 12) % 97;

        return $rest === $check;
    }

    private function localDEValidation($vat_number)
    {
        $number = substr($vat_number, 2, 9);
        $product = 10;
        $check = 0;

        foreach(range(0, strlen($number)-2) as $i) {
            $digit = (int)substr($number, $i, 1);
            $sum = (int)($digit + $product) % 10;
            if ($sum == 0) {
                $sum = 10;
            }

            $product = (2 * $sum) % 11;
        }

        if(11 - $product != 10) {
            $check = 11 - $product;
        }

        if($check === (int)substr($number, 8, 2)) {
            return true;
        }

        return false;
    }

    private function localNLValidation($vat_number)
    {
        $number = substr($vat_number, 2, 9);
        $check = (int)substr($vat_number, 10, 1);

        $total = 0;
        $multipliers = [9,8,7,6,5,4,3,2];

        foreach($multipliers as $i => $value) {
            $total += (int)substr($number, $i, 1) * $multipliers[$i];
        }

        $total = ($total % 11) > 9 ? 0 : $total % 11;

        if($total === $check) {
            return true;
        }
        return false;
    }

}
