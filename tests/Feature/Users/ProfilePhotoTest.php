<?php

declare(strict_types=1);

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

/**
 * JPEG real com metadados EXIF (incluindo GPS) para provar que são removidos.
 */
function photoWithGps(int $width = 800, int $height = 600): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 60, 120));
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // Segmento APP1 "Exif" mínimo com a etiqueta GPSInfo (0x8825), inserido logo após o SOI
    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x88\x25\x00\x04\x00\x00\x00\x01\x00\x00\x00\x1A\x00\x00\x00\x00GPS-LUANDA";
    $exif = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
    $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

    return new UploadedFile($path, 'eu.jpg', 'image/jpeg', null, true);
}

it('carrega a foto: quadrada, WebP, sem metadados, em dois tamanhos', function () {
    $user = apiLogin(User::factory()->withRole(Role::Provider)->create());

    $response = $this->post('/api/v1/me/photo', ['photo' => photoWithGps()], ['Accept' => 'application/json'])->assertOk();

    $user->refresh();
    $response->assertJsonPath('data.photo_url', "/fotos/{$user->photo}-512.webp")
        ->assertJsonPath('data.photo_thumb_url', "/fotos/{$user->photo}-128.webp");

    foreach ([512, 128] as $size) {
        $bytes = Storage::disk('local')->get("photos/{$user->photo}-{$size}.webp");
        $info = getimagesizefromstring((string) $bytes);
        expect($info[0])->toBe($size)->and($info[1])->toBe($size)->and($info['mime'])->toBe('image/webp')
            ->and(str_contains((string) $bytes, 'GPS-LUANDA'))->toBeFalse()
            ->and(str_contains((string) $bytes, 'Exif'))->toBeFalse();
    }

    $this->get("/fotos/{$user->photo}-128.webp")->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

it('trocar a foto apaga a anterior; remover volta às iniciais', function () {
    $user = apiLogin(User::factory()->create());
    $this->post('/api/v1/me/photo', ['photo' => photoWithGps()], ['Accept' => 'application/json'])->assertOk();
    $first = $user->fresh()?->photo;

    $this->post('/api/v1/me/photo', ['photo' => photoWithGps()], ['Accept' => 'application/json'])->assertOk();
    Storage::disk('local')->assertMissing("photos/{$first}-512.webp");
    $this->get("/fotos/{$first}-512.webp")->assertNotFound();

    $this->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.photo_url', null);
    expect(Storage::disk('local')->allFiles('photos'))->toBe([]);
});

it('recusa ficheiros que não são fotos, demasiado grandes ou pequenos', function () {
    apiLogin(User::factory()->create());

    $fake = UploadedFile::fake()->createWithContent('foto.jpg', '<script>alert(1)</script>');
    $this->post('/api/v1/me/photo', ['photo' => $fake], ['Accept' => 'application/json'])->assertStatus(422);
    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.gif', 400, 400)], ['Accept' => 'application/json'])->assertStatus(422);
    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('big.jpg', 400, 400)->size(6000)], ['Accept' => 'application/json'])->assertStatus(422);
    $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('tiny.jpg', 100, 100)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error.details.photo.0', 'A foto tem de ter pelo menos 200×200 píxeis.');
});

it('exige sessão para carregar e só serve nomes válidos', function () {
    $this->postJson('/api/v1/me/photo')->assertUnauthorized();
    $this->get('/fotos/../../.env-512.webp')->assertNotFound();
    $this->get('/fotos/'.str_repeat('a', 32).'-999.webp')->assertNotFound();
});

it('o cliente vê a foto do técnico no serviço e o técnico a do cliente', function () {
    $session = paidSession();
    $session->provider->user->forceFill(['photo' => str_repeat('b', 32)])->save();

    apiLogin($session->client);
    $this->getJson("/api/v1/sessions/{$session->id}")
        ->assertJsonPath('data.provider.photo_thumb_url', '/fotos/'.str_repeat('b', 32).'-128.webp');

    apiLogin($session->provider->user);
    $this->getJson("/api/v1/sessions/{$session->id}")->assertJsonPath('data.client.photo_url', null);
});
