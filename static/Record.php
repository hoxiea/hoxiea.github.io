<?php
namespace grx1;

class Record
{
    // Attributes found in Line 1 of a text record
    public $drug_name = "";      // eg. POTASSIUM CL ER, NAMENDA, CRESTOR, OMEPRAZOLE
    public $drug_strength = "";  // eg. 20MEQ, 10 MG TAB, 5 MG TABLET, etc.
    public $dc_date = "";        // starts blank, filled in if drug DCed
    public $dc_time = "";        // starts blank, filled in if drug DCed

    public $quantity_pd = -1;    // quantity per day, used for dosage calculation
    public $post = -1;           // 0 means not-billed, else billed
    public $rx_number = "";


    // Attributes found in Lines 2 and 3 of a text record
    public $pom = false;  // *POM* occasionally appears in line2 instructions
    public $dnf = false;  // *DNF* can appear in line2 instructions
    public $sig = "";
    public $NDC = "";


    // Attributes that are a functions of lines 1-3
    /*
     * dosage == how much drug are they ingesting in 24 hours, in whatever units
     *           were used in Line 1
     * This quantity is crucial for figuring out if a DC and Reassign in which,
     * say, half of a 20MG tablet was replaced by one 10MG tablet of the same
     * drug: the dosage is 10 in both cases, and that plus the same drug name
     * is enough.
     */
    public $dosage = -1;

    // Attributes that are extra
    // $input_position is the absolute position of the record in the input file
    // For example, an input_position of 20 would mean that that record was the
    // 20th record in the whole file
    public $patient_info = "";
    public $input_position = -1;

    // Class stuff
    public static $output_format = "%-8.8s %-10.10s %-8.8s %-5.5s %-14.14s %-8.8s %-7.7s %-9.9s";

    ##################

    public function __construct($lines, $patient_info, $position)
    {
        $line1 = trim($lines[1]);
        $line2 = trim($lines[2]);
        $line3 = trim($lines[3]);
        $matches = [];  // used for regex matches later

        $this->patient_info = $patient_info;
        $this->input_position = $position;
        $this->sig = $line2;

        /*
        * $line1
        * The columns at the top of each page refer to Line 1, and suggest that
        * column offsets can help us. We have the following substring indices:
        * - (7, 26) = drug name and strength; might be truncated to fit but that's fine
        * - (33, 41) = maybe discontinued time/date, maybe irrelevant stuff
        * - (78, 7) = quantity
        * - (86, 8) = quantity per day
        * - (111, 4) = Post (0 => not billing, *key!*)
        * - (123, 8) = RX number
        */
        // Easy ones first
        $this->quantity_pd = floatval(trim(substr($line1, 86, 8)));
        $this->post        = intval(trim(substr($line1, 111, 4)));
        $this->rx_number   = trim(substr($line1, 123, 8));

        $drug_info = trim(substr($line1, 7, 48));
        $this->raw_drug_info = $drug_info;
        $this->processDrugnameDrugstrength($drug_info);


        // Process the field that may contain DC date + time
        $maybe_dcinfo = substr($line1, 33, 41);
        $dc_regex = "/DISCONTINUED ON (\d{1,2}\/\d{1,2}\/\d{2}) AT (\d{1,2}:\d{2}(?:A|P))/";
        if (preg_match($dc_regex, $maybe_dcinfo, $matches)) {
            $this->dc_date = trim($matches[1]);
            $this->dc_time = $matches[2];
        }

        $this->dosage = $this->drug_strength * $this->quantity_pd;

        /*
        * $line2
        * Line 2 contains a 1-2 letter code, and then the RX instructions.
        * Most of the information in the RX instructions is captured in
        * quantity-per-day, though, so we just check for the very-occasional
        * POM (patient's own medicine) or DNF that sometimes appears in the instructions
        */
        if (preg_match("/\**\s*POM\s*\**/", $line2)) { // [zero+ *][zero+ spaces][POM][zero+ spaces][zero+ *]
            $this->pom = true;
        }

        if (preg_match("/\**\s*DNF\s*\**/", $line2)) { // [zero+ *][zero+ spaces][DNF][zero+ spaces][zero+ *]
            $this->dnf = true;
        }

        /*
        * $line3
        * Line 3 contains the drug's NDC code, which we grab because it's here.
        */
        if (preg_match("/NDC: ([\d\- ]+)/", $line3, $matches)) {
            $this->NDC = $matches[1];
        } else {
            throw new Exception("line3 didn't contain a correctly formatted NDC code!");
        }

        $this->checkConsistency();
    } // end of constructor

    // Constructor helper functions
    private function processDrugnameDrugstrength($drug_info)
    {
        // Extract the drug_name and the drug_strength
        $drug_info = preg_replace("/,/", "", $drug_info);
        $drug_info = preg_replace("/\s+/", " ", $drug_info);

        if (!preg_match('/\d+/', $drug_info, $matches)) {
            // no digits: examples = MULTIVITAMIN ONE-DAILY or THERAPEUTIC-M TAB
            $this->drug_name = $drug_info;
            $this->drug_strength = "1";
        } elseif (preg_match('/(.*) ([\d\.]+.*)/', $drug_info, $matches)) {
            // So there's at least one digit, which suggests drugname + strength
            /*
             * Stuff, space, one or more <digits and periods>, then stuff
             * Examples: SIMVASTATIN 20 MG TAB, LISINOPRIL 5 MG TAB, GABAPENTIN 600MG
             */
            $this->drug_name     = trim($matches[1]);
            $this->drug_strength = trim($matches[2]);
        } elseif (preg_match('/(.*?)(\d)(.*)/', $drug_info, $matches)) {
            /*
             * Cases where there's a digit but no space before it
             * Examples:
             */
            $this->drug_name = $matches[1];
            $this->drug_strength = $matches[2] . $matches[3];
        } else {
            echo "DrugInfo Problem: $drug_info</br>";
            throw new Exception("Couldn't extract drug_name and drug_strength");
        }
    }

    public function isDCed()
    {
        return ($this->dc_date != "");
    }

    public function isActive()
    {
        return !$this->isDCed();
    }

    public function isBilled()
    {
        return ($this->post > 0);
    }

    public function isUnbilled()
    {
        return !$this->isBilled();
    }

    public function isPOM()
    {
        return $this->pom;
    }

    public function isDNF()
    {
        return $this->dnf;
    }

    public function isSigWeek()
    {
        $days = ["/MONDAY/", "/TUESDAY/", "/WEDNESDAY/", "/THURSDAY/", "/FRIDAY/", "/SATURDAY/", "/SUNDAY/"];
        $phrases = ["/A WEEK/", "/WEEKLY/", "/EVERY WEEK/", "/EVERY OTHER/"];
        $days_unclear = ["/MON\b/", "/TUES?\b/", "/WED\b/", "/THURS?\b/", "/FRI\b/", "/SAT\b/", "/SUN\b/"];
        $patterns = array_merge($days, $phrases, $days_unclear);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->sig)) {
                return true;
            }
        }
        return false;
    }

    public function isSigMisc()
    {
        $patterns = ["/\bHOLD\b/", "/\bSBP\b/", "/\bHR\b/"];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->sig)) {
                return true;
            }
        }
        return false;
    }

    public function hasBrandInSig()
    {
        return (strpos($this->sig, "BRAND") !== false);
    }

    public function looksShortDated()
    {
        $preposition = "(X|FOR)";
        $freq = "(\d{1,2}|ONE|TWO|THREE|FOUR|FIVE|SIX|SEVEN|EIGHT|NINE|TEN|ELEVEN|TWELVE|THIRTEEN|FOURTEEN)";
        $units = "(DAYS|DAY|WEEKS|WEEK|MONTH|MONTHS)";
        $pattern = "/$preposition( )?$freq( )?$units/";
        return preg_match($pattern, $this->sig) === 1;
    }

    public function origOrUD()
    {
        return $this->hasOrigInDrugField() || $this->hasUDInDrugField();
    }

    private function hasOrigInDrugField()
    {
        $drugfield = "{$this->drug_name} {$this->drug_strength}";
        $pattern = "/(\s|\W)ORIG(\s|\W|\b)/";
        return preg_match($pattern, $drugfield);
    }

    private function hasUDInDrugField()
    {
        $drugfield = "{$this->drug_name} {$this->drug_strength}";
        $pattern = "/(\s|\W)UD(\s|\W|\b)/";
        return preg_match($pattern, $drugfield);
    }

    public function isSpecialDrug()
    {
        $patterns = ["SOLN", "GELCAP", "SOFT", "DROPS", "ODT"];
        foreach ($patterns as $pattern) {
            if (strpos($this->raw_drug_info, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    public function __toString()
    {
        return "$this->rx_number  $this->patient_info  $this->drug_name  $this->dosage";
    }

    public function forFile($status, $action)
    {
        list($name, $room) = explode(" | ", $this->patient_info);
        list($lastname, $firstname) = explode(", ", $name);
        $drug_info = $this->drug_name . " " . $this->drug_strength;

        // By default, a Record's $dc_date is the empty string
        // So if it has a dc_date, it'll get printed; otherwise nothing appears,
        // which is the correct behavior in both cases
        $line1 = sprintf(
            self::$output_format,
            $this->rx_number,
            $lastname,
            $firstname,
            $room,
            $drug_info,
            $this->dc_date,
            $status,
            $action
        );
        return $line1 . nl();
    }

    private function checkConsistency()
    {
        if ($this->isDCed()) {
            assert($this->dc_date != "");
            assert($this->dc_time != "");
        }
        assert($this->drug_name != "");
        assert($this->rx_number != "");
        assert($this->post >= 0);
        assert($this->quantity_pd >= 0, "quantity_pd for ".$this->rx_number);
        assert($this->dosage >= 0, "dosage for ".$this->rx_number);
    }
}
