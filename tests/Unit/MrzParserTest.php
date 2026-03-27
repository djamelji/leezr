<?php

namespace Tests\Unit;

use App\Core\Documents\MrzParser;
use App\Core\Documents\MrzResult;
use PHPUnit\Framework\TestCase;

class MrzParserTest extends TestCase
{
    private MrzParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MrzParser();
    }

    /**
     * Regular text without any MRZ pattern should return null.
     */
    public function test_returns_null_for_text_without_mrz(): void
    {
        $text = "This is a regular document with no machine readable zone.\nJust some text and numbers 12345.";

        $result = $this->parser->parse($text);

        $this->assertNull($result);
    }

    /**
     * Empty string should return null.
     */
    public function test_returns_null_for_empty_text(): void
    {
        $result = $this->parser->parse('');

        $this->assertNull($result);
    }

    /**
     * TD3 passport MRZ (2 lines x 44 chars) should be detected and parsed.
     *
     * The rakibdevs/mrz-parser library returns keys like 'first_name', 'last_name',
     * 'nationality' which MrzResult::fromParsed maps correctly via fallback.
     * However, 'card_no' does not map to 'document_number', and 'date_of_birth'/
     * 'date_of_expiry' do not map to 'birth_date'/'expiry_date' — so those fields
     * remain null due to the key mismatch between the library and the DTO.
     */
    public function test_parses_td3_passport_mrz(): void
    {
        $mrz = "P<FRADUPONT<<JEAN<MARIE<<<<<<<<<<<<<<<<<<<<<\n"
             . '20AB123456FRA8501011M3001012<<<<<<<<<<<<<<02';

        $result = $this->parser->parse($mrz);

        $this->assertInstanceOf(MrzResult::class, $result);

        // Fields that the library maps correctly through fromParsed fallback keys
        $this->assertSame('DUPONT', $result->lastName);
        $this->assertSame('JEAN MARIE', $result->firstName);
        $this->assertSame('France', $result->nationality);
        $this->assertSame('Passport', $result->documentType);

        // After ADR-411 fix: fromParsed now maps library keys correctly
        $this->assertNotNull($result->documentNumber);
        $this->assertNotNull($result->birthDate);
        $this->assertNotNull($result->expiryDate);
        $this->assertNotNull($result->sex);
        $this->assertNotNull($result->country);
    }

    /**
     * TD1 ID card MRZ (3 lines x 30 chars) should be detected and parsed.
     *
     * TD1 layout per ICAO 9303:
     *   line 1 (30): type(2) + issuer(3) + doc_number(9) + check(1) + optional(15)
     *   line 2 (30): DOB(6) + check(1) + sex(1) + DOE(6) + check(1) + nationality(3) + optional(11) + check(1)
     *   line 3 (30): name (surname << given_names, padded with <)
     *
     * Same key-mismatch caveat as TD3: documentNumber, birthDate, expiryDate
     * are null because the library uses different output keys.
     */
    public function test_parses_td1_id_card_mrz(): void
    {
        // Proper 30-char TD1 lines following ICAO positional layout
        $mrz = "IDFRA20AB123456<<<<<<<<<<<<<<<\n"
             . "8501011M3001016FRA<<<<<<<<<<<4\n"
             . 'DUPONT<<JEAN<MARIE<<<<<<<<<<<<';

        $result = $this->parser->parse($mrz);

        $this->assertInstanceOf(MrzResult::class, $result);

        // The library's TD1 parser reads type from line1[0..1] = "ID"
        // which maps to "Travel Document (TD1)" in the library.
        $this->assertSame('Travel Document (TD1)', $result->documentType);

        // Name is on line 3 for TD1
        $this->assertSame('DUPONT', $result->lastName);
        $this->assertSame('JEAN MARIE', $result->firstName);

        // Nationality from line2[15..17] = "FRA" -> "France"
        $this->assertSame('France', $result->nationality);
    }

    /**
     * MRZ embedded in longer OCR text (with noise lines) should still be extracted.
     */
    public function test_extracts_mrz_from_noisy_ocr_text(): void
    {
        $ocrText = "REPUBLIQUE FRANCAISE\n"
                 . "PASSEPORT\n"
                 . "Nom: DUPONT\n"
                 . "Prenom: JEAN MARIE\n"
                 . "\n"
                 . "P<FRADUPONT<<JEAN<MARIE<<<<<<<<<<<<<<<<<<<<<\n"
                 . "20AB123456FRA8501011M3001012<<<<<<<<<<<<<<02\n"
                 . "\n"
                 . "Issued: Paris";

        $result = $this->parser->parse($ocrText);

        $this->assertInstanceOf(MrzResult::class, $result);
        $this->assertSame('DUPONT', $result->lastName);
        $this->assertSame('JEAN MARIE', $result->firstName);
    }

    /**
     * toArray() should only contain non-null fields.
     */
    public function test_mrz_result_to_array_filters_nulls(): void
    {
        $mrz = "P<FRADUPONT<<JEAN<MARIE<<<<<<<<<<<<<<<<<<<<<\n"
             . '20AB123456FRA8501011M3001012<<<<<<<<<<<<<<02';

        $result = $this->parser->parse($mrz);

        $this->assertInstanceOf(MrzResult::class, $result);

        $array = $result->toArray();

        // Null fields should be excluded from the array
        foreach ($array as $key => $value) {
            $this->assertNotNull($value, "Key '{$key}' should not be null in toArray() output");
        }

        // Known non-null fields should be present
        $this->assertArrayHasKey('last_name', $array);
        $this->assertArrayHasKey('first_name', $array);
        $this->assertArrayHasKey('nationality', $array);
        $this->assertArrayHasKey('document_type', $array);
    }
}
