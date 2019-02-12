<?php
namespace grx1\dosis;

///////////////////////////////////
// PART 1: PROCESSING USAGE FILE //
///////////////////////////////////
/*
 * Input: [String], where each String is a line from the usage file
 * Output: [UsageLine]
 */
function usagedata_to_UsageLines($ud, $verbose = false)
{
    if (looks_like_usagefile($ud)) {
        $ud = drop_elements($ud, 1);
    }

    $usageLines = [];
    $filteredDruginfos = [];
    foreach ($ud as $linestring) {
        try {
            $usageLines[] = new \grx1\dosis\UsageLine($linestring);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Filtered:') !== false) {
                $startOfDruginfo = strpos($msg, ' ') + 1;
                $druginfo = substr($msg, $startOfDruginfo);
                if (!isset($filteredDruginfos[$druginfo])) {
                    $filteredDruginfos[$druginfo] = true;
                }
            } else {
                switch ($msg) {
                    case "NDC11 Error":
                        // echo make_p("NDC11 Error");
                        continue;
                    case "line no good":  // constructor
                        // echo make_p("line no good");
                        continue;
                    case "line_to_array fail":
                        echo make_p("line_to_array, in UsageLine constructor");
                        continue;
                    default:
                        echo make_p($msg);
                        continue;
                }
            }
        }
    }
    return [$usageLines, array_keys($filteredDruginfos)];
}


/*
 * usagelines2Drugs_NDC11
 *
 * Converts the UsageLines into UsageDrugs, based on exact NDC11 matches.
 */
function usagelines2drugs_NDC11($usageLines)
{
    $drugs = [];
    foreach ($usageLines as $ul) {
        $ndc11 = $ul->ndc11;
        if (isset($drugs[$ndc11])) {
            $drugs[$ndc11]->absorbUsageLineNDC11($ul);
        } else {
            $drugs[$ndc11] = UsageDrug::fromUsageLine($ul);
        }
    }

    // Make sure all NDCs are unique
    $allNDC11s = [];
    foreach ($drugs as $d) {
        $ndcs = $d->getNDC11s();
        assert(count($ndcs) == 1);
        $ndc = $ndcs[0];
        if (!isset($allNDC11s[$ndc])) {
            $allNDC11s[$ndc] = true;
        }
    }
    assert(count($allNDC11s) == count($drugs));
    return $drugs;
}



///////////////////
// PROBLEM DRUGS //
///////////////////
/*
 * Mutates the UsageDrugs in array $uds
 */
function annotate_problem_usagedrugs(&$uds, $strings, $regexes)
{
    list($usedStrings, $unusedStrings) = update_problem_drugs_strings($uds, $strings);
    $usedRegexes = update_problem_drugs_regexes($uds, $regexes);
    return [$usedStrings, $unusedStrings, $usedRegexes];
}

function update_problem_drugs_strings(&$uds, $strings)
{
    $usedStrings = [];   // [String -> [Drug]]
    foreach ($strings as $string) {
        foreach ($uds as $ud) {
            if ($ud->absorbProblemDrugString($string)) {
                $usedStrings[$string][] = $ud;
                break;   // first match is enough to be a Problem Drug
            }
        }
    }
    $unusedStrings = array_diff($strings, array_keys($usedStrings));
    assert(count($usedStrings) + count($unusedStrings) == count($strings));
    return [$usedStrings, $unusedStrings];
}

function update_problem_drugs_regexes(&$uds, $regexes)
{
    $usedRegexes = [];
    foreach ($uds as $ud) {
        foreach ($regexes as $regex => $description) {
            if ($ud->absorbProblemDrugRegex($regex)) {
                $usedRegexes[$regex][] = $ud;
                break;   // first match is enough to be a Problem Drug
            }
        }
    }
    return $usedRegexes;
}


///////////////////////////////////
// PART 3: MERGING AND FINISHING //
///////////////////////////////////
function match_drugs_ndc11(&$uds, &$dds)
{
    foreach ($dds as $dd) {
        foreach ($uds as $ud) {
            if ($ud->absorbDosisMatchNDC11($dd)) {
                $result = $dd->absorbUsageMatch($ud);
                if (!$result) {
                    throw new \Exception("DD matched UD, but UD didn't match DD!?");
                }
                break;
            }
        }
    }
}


function find_unmatched_dosis_drugs($dosisDrugs)
{
    return array_filter($dosisDrugs, function ($dd) { return $dd->lacksUsageMatch(); });
}

function find_included_slow_movers($usageDrugs, $cutoffNum)
{
    return array_values(array_filter($usageDrugs, function ($ud) use ($cutoffNum) {
        return $ud->isIncludedSlowMover($cutoffNum);
    }));
}

function find_missing_fast_movers($usageDrugs, $cutoffNum)
{
    return array_values(array_filter($usageDrugs, function ($ud) use ($cutoffNum) {
        return $ud->isMissingFastMover($cutoffNum);
    }));
}

/////////////////////
// GENERAL HELPERS //
/////////////////////

/*
 * Inputs:
 * - $array: an array of things that you'd like to index
 * - $indexing_function: this function can be applied to each element of
 *   $array, and it returns a value that can be used as a key in an associative
 *   array (integer or string)
 * - $preserve_array_keys: Bool. The keys in $array could actually have
 *   meaning. If you'd like, this function can preserve each key when its
 *   corresponding value is assigned to an indexed subarray. For example, if
 *   $drugs has been sorted by usage, then the keys are the usage ranks, and
 *   keeping these around makes it easy to figure out the rank of each dosis
 *   drug.
 *
 * Output:
 * - an array where all elements of $array with the same $indexing_function
 *   value have been grouped together in an array that's mapped to by that key
 */
function convert_to_indexed_array($array, $indexing_function, $preserve_array_keys = true)
{
    $result = [];
    foreach ($array as $key => $value) {
        $index = call_user_func($indexing_function, $value);
        if (isset($result[$index])) {  // If this index has already been seen...
            if ($preserve_array_keys) {  // And you want to preserve key => value...
                $result[$index][$key] = $value;
            } else { // And you don't care about the preserving key => value...
                $result[$index][] = $value;
            }
        } else {  // This index hasn't already been seen...
            if ($preserve_array_keys) {  // But you want to preserve key => value...
                $result[$index] = array($key => $value);
            } else {  // And you don't care about the preserving key => value...
                $result[$index] = [$value];
            }
        }
    }
    return $result;
}

// Flatten an array of arrays, like those produced by convert_to_indexed_array
// Note that this doesn't preserve the keys, if preserve_array_keys was true above
function flatten_indexed_array($array)
{
    $flat = [];
    foreach ($array as $index => $a) {
        $flat = array_merge($flat, $a);  // NOTE: performance enhancement possibly relevant here
    }
    return $flat;
}


/**
 * merge_similar_usage_drugs
 *
 * Go through an array of Drugs, merging similar pairs based on Drug::absorbSimilarDrug.
 *
 * Inputs:
 * @drugs : [Drug]
 * @prefix_length : Int. How many characters to use for the indexed array keys.
 *     (prefix_length matters only for computational complexity, since a flat array of Drugs is returned)
 *
 * Outputs:
 * @output : [mixed], where
 *   $output[0] : [Drug], the merged Drugs
 *   $output[1] : [[Drug, Drug]], an array of pairs of Drugs that were merged
 *
 * We use an indexed array (explained at top) so that we're not comparing
 * radically different drugs.
 * But within a subarray (that contains only drugs with the same first letters
 * of drugname), we want to compare all pairs of drugs, not just adjacent ones
 * in alphabetical order
 *
 * If we find a pair of Drugs that are likely duplicates, the trick is to get
 * all of the information from those duplicates into the same single Drug
 * object, and to keep track of that Drug object so that it (the Drug
 * containing the updated information) emerges from the chaos of merging.
 *
 * Complicating this is that there could be triplets: three Drugs that are all
 * actually the same.
 *
 * To do this, for each prefixed subarray, we start walking through pairs with
 * a classic double loop.
 * For each pair of drugs ("the earlier drug" via the outer loop and "the later
 * drug" via the inner loop), we:
 * 1. Try to absorb the later Drug into the earlier Drug via Drug->absorbSimilarDrug.
 *    This returns a boolean of success/failure.
 * 2. If absorbSimilarDrug succeeded, then we add the pair to $mergedDuplicates
 *
 * We also replace the later Drug from the subarray with null, so that it
 * won't eventually be an "earlier Drug" and so that the first Drug of any
 * duplicate sets is always the one that contains the most information.
 */
function merge_similar_usage_drugs($drugs, $prefix_length = 4)
{
    // Build an indexed Drug array, where keys are the first $prefix_length letters of the first cleanedDruginfo
    $drugsIndexed = convert_to_indexed_array($drugs, function ($d) use ($prefix_length) {
        return substr($d->getCleanedDruginfos()[0], 0, $prefix_length);
    }, false);  // false => reset the keys for each subarray

    // As we merge similar Drugs, we modify $drugsIndexed directly
    // We also need to keep track of the merges we make
    $mergedDuplicates = [];

    // Merge
    foreach ($drugsIndexed as $prefix => &$a) {
         // &$a is a REFERENCE, so that mutations apply to $drugsIndexed
        for ($ii = 0; $ii < count($a) - 1; $ii++) {  // can use count() here, since we're nulling, not unsetting
            for ($jj = $ii + 1; $jj < count($a); $jj++) {
                if ($a[$ii] !== null && $a[$jj] !== null) {
                    $d1 = $a[$ii];  // earlier drug
                    $d2 = $a[$jj];  // later drug
                    if ($d1->absorbSimilarDrug($d2)) {
                        $mergedDuplicates[] = [$d1, $d2];
                        $a[$jj] = null;  // null out the later drug
                    }
                }
            }
        }
        $a = array_values(array_filter($a));  // filter out the nulls, then reset the keys
    }

    // Flatten
    $mergedDrugs = flatten_indexed_array($drugsIndexed);
    return array($mergedDrugs, $mergedDuplicates);
}


function find_drugs_exact_string_match($drugs, $strings)
{
    $matchingDrugs = [];       // [String1 -> [Drug], String2 -> [Drug], ...]
    $nonMatchingDrugs = [];    // [Drug]
    $numMatchingDrugs = 0;

    foreach ($drugs as $drug) {
        $drugMatchedAString = false;
        foreach ($strings as $string) {
            if ($drug->rawDruginfoContainsString($string)) {
                $matchingDrugs[$string][] = $drug;
                $drugMatchedAString = true;
                $numMatchingDrugs++;
                break;  // a drug could match more than one string; the first one is enough to flag as a match
            }
        }

        if (!$drugMatchedAString) {
            $nonMatchingDrugs[] = $drug;
        }
    }

    assert($numMatchingDrugs + count($nonMatchingDrugs) == count($drugs));
    return [$matchingDrugs, $nonMatchingDrugs, $numMatchingDrugs];
}


function find_drugs_match_regex($drugs, $regexes)
{
    $matchingDrugs = [];       // [String -> [Drug]]
    $nonMatchingDrugs = [];    // [Drug]
    $numMatchingDrugs = 0;

    foreach ($drugs as $drug) {
        $drugMatchedARegex = false;
        foreach ($regexes as $regex) {
            $matchIndex = $drug->findRawDruginfoRegexMatch($regex);
            if ($matchIndex >= 0) {
                $matchingDrugs[$regex][] = $drug;
                $drugMatchedARegex = true;
                $numMatchingDrugs++;
                break;
            }
        }

        if (!$drugMatchedARegex) {
            $nonMatchingDrugs[] = $drug;
        }
    }

    assert($numMatchingDrugs + count($nonMatchingDrugs) == count($drugs));
    return [$matchingDrugs, $nonMatchingDrugs, $numMatchingDrugs];
}


/*
 * Stolen from AnniversaryLine::csvLineToArray
 */
function line_to_array_minimal_quotes($line)
{
    if (strpos($line, '"') === false) {
        // No double quotes, so presumably no commas in any fields
        return explode(",", $line);
    }

    // So there's a double quote somewhere... must be one or more
    // fields that have commas in them. Make sure we have an even number:
    assert(substr_count($line, '"') % 2 === 0,
        "odd number of double quote marks in line $line"
    );

    // Replace each non-greedy quoted field with a non-quoted
    // version that has all commas replaced by the empty string
    $quoteRegex = '~"(.*?)"~';  //   ? => non-greedy
    $newCSVLine = preg_replace_callback(
        $quoteRegex,
        function ($matches) {
            return str_replace(',', '', $matches[1]);
        },
        $line
    );
    // echo make_p($line);      // might help debugging
    // echo make_p($newCSVLine);
    return explode(",", $newCSVLine);
}
