<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Transaction;
use App\Support\Base64Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    foreach (['images/product_image', 'images/purchase_image', 'images/billty_image', 'images/company_images'] as $dir) {
        foreach (glob(public_path($dir).'/*') ?: [] as $file) {
            if (is_file($file) && preg_match('/^\d+\.(hardening|purchase|billty|product)/', basename($file))) {
                File::delete($file);
            }
        }
    }
});

it('stores a product image with a safe extension chosen by the server', function () {
    $file = UploadedFile::fake()->image('picture.JPG', 20, 20);

    $stored = Product::saveImageFile($file, 'hardening');

    expect($stored)->toMatch('/\.jpg$/');
    expect(file_exists(Product::imageDirectory().'/'.$stored))->toBeTrue();
});

it('rejects a script uploaded as a product image', function () {
    $file = UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]);');

    expect(fn () => Product::saveImageFile($file, 'hardening'))->toThrow(ValidationException::class);
});

it('cleans path characters out of a name before it reaches the file name', function () {
    expect(Base64Upload::safeName('../../public/x y.php'))->toBe('publicxyphp');
    expect(Base64Upload::safeName('///'))->toBe('file');
    expect(Base64Upload::safeName('', 'product'))->toBe('product');

    $stored = Product::saveImageFile(UploadedFile::fake()->image('a.png', 10, 10), '../../hardening');

    expect($stored)->not->toContain('/')->not->toContain('..');
});

it('rejects a script as a company logo, purchase attachment and bilty file', function () {
    $script = fn () => UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;');

    $logo = new ReflectionMethod(Company::class, 'saveLogoFile');
    $logo->setAccessible(true);

    expect(fn () => $logo->invoke(null, $script(), 'Acme'))->toThrow(ValidationException::class);
    expect(fn () => Transaction::saveImageFile($script()))->toThrow(ValidationException::class);
    expect(fn () => Base64Upload::publicExtensionFor($script(), true))->toThrow(ValidationException::class);
});

it('accepts a pdf only where a pdf is allowed', function () {
    $pdf = fn () => UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

    expect(Base64Upload::publicExtensionFor($pdf(), true))->toBe('pdf');
    expect(fn () => Base64Upload::publicExtensionFor($pdf(), false))->toThrow(ValidationException::class);
});

it('does not trust the extension a bilty data url claims', function () {
    $request = (object) ['billty_image' => 'data:image/php;base64,'.base64_encode('<?php echo 1;')];

    expect(Transaction::storeBilltyImage($request, 'kept.jpg'))->toBe('kept.jpg');
});
