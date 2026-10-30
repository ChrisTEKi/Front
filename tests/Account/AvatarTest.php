<?php

namespace App\Tests\Account;

use App\Entity\User;
use App\Entity\UserAvatar;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photo de profil : toute image reçue est décodée puis ré-encodée en JPEG carré (rien de ce que contenait le fichier d'origine n'est conservé).
 */
final class AvatarTest extends AppTestCase
{
    private const UPLOAD = '/compte/api/photo';

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function signedIn(string $email = 'c@example.com'): User
    {
        $user = $this->createUser($email);
        $this->signIn($user);

        return $user;
    }

    private function png(int $width, int $height, bool $transparent = false): string
    {
        $img = imagecreatetruecolor($width, $height);
        if ($transparent) {
            imagesavealpha($img, true);
            imagealphablending($img, false);
            imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        } else {
            imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** Envoie un fichier comme le fait le navigateur (multipart + jeton CSRF en en-tête). */
    private function upload(string $bytes, string $name = 'photo.png', string $mime = 'image/png'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gobuy-avatar-');
        self::assertIsString($path);
        $this->tmp[] = $path;
        file_put_contents($path, $bytes);

        $this->client->request('POST', self::UPLOAD, [], ['photo' => new UploadedFile($path, $name, $mime, null, true)], [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.7',
        ]);
        $this->em()->clear();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Lit la photo servie par /profil/photo. */
    private function served(): \GdImage
    {
        $this->client->request('GET', '/profil/photo');
        self::assertResponseIsSuccessful();
        $image = imagecreatefromstring((string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    public function testPngIsReEncodedAsASquareJpegAndServedBack(): void
    {
        $user = $this->signedIn();

        $r = $this->upload($this->png(800, 400));

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($r['ok']);
        self::assertStringStartsWith('/profil/photo?v=', $r['photoUrl']);
        self::assertTrue($this->reload($user)->hasAvatar());

        $this->client->request('GET', '/profil/photo');
        $response = $this->client->getResponse();
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith("\xFF\xD8", (string) $response->getContent(), 'JPEG réel, pas le PNG d\'origine.');
        $image = $this->served();
        self::assertSame(400, imagesx($image));
        self::assertSame(400, imagesy($image), 'Recadrée en carré.');
    }

    public function testLargeImagesAreReducedTo512(): void
    {
        $this->signedIn();

        $this->upload($this->png(2000, 1000));

        $image = $this->served();
        self::assertSame(512, imagesx($image));
        self::assertSame(512, imagesy($image));
    }

    public function testTransparencyBecomesWhiteNotBlack(): void
    {
        $this->signedIn();

        $this->upload($this->png(100, 100, transparent: true));

        $image = $this->served();
        $rgb = imagecolorat($image, 5, 5);
        self::assertGreaterThan(240, ($rgb >> 16) & 0xFF);
        self::assertGreaterThan(240, ($rgb >> 8) & 0xFF);
        self::assertGreaterThan(240, $rgb & 0xFF);
    }

    public function testExifOrientationIsAppliedAndMetadataIsStripped(): void
    {
        $this->signedIn();

        // Image 40 × 20 : moitié gauche rouge, moitié droite bleue, avec « orientation 6 » (à tourner de 90° dans le sens horaire).
        $img = imagecreatetruecolor(40, 20);
        imagefilledrectangle($img, 0, 0, 19, 19, (int) imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 20, 0, 39, 19, (int) imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 100);
        $jpeg = (string) ob_get_clean();
        // Segment APP1 « Exif » minimal : un seul champ, Orientation (0x0112) = 6.
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($app1) + 2).$app1.substr($jpeg, 2);

        $this->upload($withExif, 'phone.jpg', 'image/jpeg');

        // Tournée de 90° horaire : la moitié gauche (rouge) passe en HAUT.
        $image = $this->served();
        $top = imagecolorat($image, 10, 3);
        $bottom = imagecolorat($image, 10, 16);
        self::assertGreaterThan(150, ($top >> 16) & 0xFF, 'Le haut est rouge.');
        self::assertGreaterThan(150, $bottom & 0xFF, 'Le bas est bleu.');
        // Plus aucune métadonnée dans le fichier enregistré.
        $this->client->request('GET', '/profil/photo');
        self::assertStringNotContainsString("Exif\x00\x00", (string) $this->client->getResponse()->getContent());
    }

    public function testNonImagesAndSvgAreRefused(): void
    {
        $user = $this->signedIn();

        $r = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml');
        self::assertSame(415, $this->httpStatus());
        self::assertSame('photo_unsupported', $r['error']);

        // Un script déguisé en image : refusé sur son CONTENU, pas sur son nom ni sur le type annoncé.
        $r = $this->upload('<?php system($_GET["c"]);', 'photo.jpg', 'image/jpeg');
        self::assertSame(415, $this->httpStatus());
        self::assertSame('photo_unsupported', $r['error']);

        self::assertFalse($this->reload($user)->hasAvatar());
    }

    public function testDecompressionBombIsRefusedBeforeDecoding(): void
    {
        $user = $this->signedIn();
        // PNG valide de 1 × 1 dont l'en-tête annonce 20 000 × 20 000 pixels.
        $bomb = substr_replace($this->png(1, 1), pack('NN', 20000, 20000), 16, 8);

        $r = $this->upload($bomb);

        self::assertSame(413, $this->httpStatus());
        self::assertSame('photo_too_large', $r['error']);
        self::assertFalse($this->reload($user)->hasAvatar());
    }

    public function testMissingFileIsRefused(): void
    {
        $this->signedIn();

        $r = $this->api('POST', self::UPLOAD, []);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('photo_required', $r['error']);
    }

    public function testNewPhotoReplacesTheOldOneAndRemovalDeletesIt(): void
    {
        $user = $this->signedIn();
        $this->upload($this->png(100, 100));
        $first = $this->reload($user)->avatarVersion();

        static::mockTime((new \DateTimeImmutable())->modify('+1 minute'));
        $this->upload($this->png(120, 120));
        self::assertNotSame($first, $this->reload($user)->avatarVersion(), 'La version change : le navigateur recharge la photo.');
        self::assertSame(1, $this->em()->getRepository(UserAvatar::class)->count([]), 'Une seule photo par compte.');

        $r = $this->api('POST', self::UPLOAD.'/supprimer');
        self::assertTrue($r['ok']);
        self::assertNull($r['photoUrl']);
        self::assertFalse($this->reload($user)->hasAvatar());
        self::assertSame(0, $this->em()->getRepository(UserAvatar::class)->count([]));

        $this->client->request('GET', '/profil/photo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testBrowserRevalidatesWithEtag(): void
    {
        $this->signedIn();
        $this->upload($this->png(100, 100));

        $this->client->request('GET', '/profil/photo');
        $etag = (string) $this->client->getResponse()->headers->get('ETag');
        self::assertNotSame('', $etag);

        $this->client->request('GET', '/profil/photo', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testEachPersonOnlySeesTheirOwnPhoto(): void
    {
        $this->signedIn('a@example.com');
        $this->upload($this->png(100, 100));

        // Autre navigateur, autre compte sans photo : 404, jamais la photo de l'autre. Visiteur : pas d'accès.
        $this->newBrowser();
        $this->client->request('GET', '/profil/photo');
        self::assertResponseRedirects();

        $this->signIn($this->createUser('b@example.com'));
        $this->client->request('GET', '/profil/photo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUploadNeedsTheCsrfToken(): void
    {
        $this->signedIn();
        $path = tempnam(sys_get_temp_dir(), 'gobuy-avatar-');
        self::assertIsString($path);
        $this->tmp[] = $path;
        file_put_contents($path, $this->png(50, 50));

        $this->client->request('POST', self::UPLOAD, [], ['photo' => new UploadedFile($path, 'p.png', 'image/png', null, true)]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadsArePerAccountRateLimited(): void
    {
        $user = $this->signedIn();

        for ($i = 0; $i < 10; ++$i) {
            $this->upload($this->png(10, 10));
            self::assertSame(200, $this->httpStatus());
        }
        $r = $this->upload($this->png(10, 10));

        self::assertSame(429, $this->httpStatus());
        self::assertSame('too_many_attempts', $r['error']);
        self::assertTrue($this->reload($user)->hasAvatar());
    }

    public function testPhotoDisappearsWithTheAccount(): void
    {
        $user = $this->signedIn();
        $this->upload($this->png(50, 50));
        self::assertSame(1, $this->em()->getRepository(UserAvatar::class)->count([]));

        // La purge supprime la ligne `users` : la clé étrangère ON DELETE CASCADE emporte la photo.
        $this->em()->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);

        self::assertSame(0, $this->em()->getRepository(UserAvatar::class)->count([]));
    }

    public function testAdminAndSupplierCanHaveAPhotoToo(): void
    {
        $admin = $this->createAdminUser('admin@example.com');
        $this->signInAdmin($admin);

        $this->upload($this->png(80, 80));

        self::assertSame(200, $this->httpStatus());
        self::assertTrue($this->reload($admin)->hasAvatar());
    }
}
