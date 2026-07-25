<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Customer CSV import. The real-world file that exposed these came out of Excel
 * with a UTF-8 BOM and phone numbers in half a dozen formats; each of those is
 * pinned here so the import can't silently swallow a whole file again.
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    protected function upload(string $contents)
    {
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, $contents);

        return $this->actingAs($this->admin())->post('/admin/customers/import', [
            'file' => new UploadedFile($path, 'customers.csv', 'text/csv', null, true),
        ]);
    }

    public function test_a_utf8_bom_does_not_swallow_the_whole_file(): void
    {
        // Excel prefixes the first header cell with EF BB BF.
        $this->upload("\xEF\xBB\xBFname,phone,email,notes\nShamim,01860988859,,\n");

        $this->assertDatabaseHas('customers', ['name' => 'Shamim', 'phone' => '01860988859']);
    }

    public function test_phone_formats_all_normalise_to_one_customer(): void
    {
        $this->upload("name,phone,email,notes\n"
            ."A,1711195772,,\n"                  // missing leading zero
            ."B,01711-195772,,\n"                // dashes
            ."C,+880 1711-195772,,\n"            // country code + spaces
            ."D,8801711195772,,\n");             // country code, no plus

        $this->assertSame(1, Customer::count());
        $this->assertDatabaseHas('customers', ['phone' => '01711195772']);
    }

    public function test_excel_mangled_numbers_are_reported_not_imported(): void
    {
        $res = $this->upload("name,phone,email,notes\nMehzabin,8.80192E+12,,\nGood,01711195772,,\n");

        $this->assertSame(1, Customer::count());
        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('scientific notation', implode(' ', $errors));
    }

    public function test_invalid_and_empty_rows_are_skipped_with_reasons(): void
    {
        $this->upload("name,phone,email,notes\n"
            ."NoPhone,,,\n"
            .",01711195772,,\n"
            ."TooShort,171129112,,\n"
            ."Good,01711195772,,\n"
            ."\n");                              // Excel's trailing blank line

        $this->assertSame(1, Customer::count());
        $joined = implode(' ', session('import_errors'));
        $this->assertStringContainsString('no phone number', $joined);
        $this->assertStringContainsString('no name', $joined);
        $this->assertStringContainsString('not valid Bangladeshi mobile', $joined);
    }

    public function test_a_file_without_the_required_columns_is_rejected(): void
    {
        $res = $this->upload("customer,mobile\nA,01711195772\n");

        $this->assertSame(0, Customer::count());
        $res->assertSessionHas('error');
    }
}
