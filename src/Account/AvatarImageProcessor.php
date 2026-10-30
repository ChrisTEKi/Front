<?php

namespace App\Account;

/**
 * Transforme une image envoyée par un utilisateur en photo de profil SÛRE : JPEG carré de 512 px au plus.
 *
 * Pourquoi tout ré-encoder au lieu de garder le fichier reçu :
 *   - un fichier « image » peut cacher autre chose (script, SVG avec JavaScript, métadonnées GPS d'un téléphone…) :
 *     décoder puis ré-écrire les pixels élimine tout ce qui n'est pas l'image ;
 *   - on n'accepte que des formats raster (JPEG, PNG, WebP, GIF) reconnus par leur CONTENU, jamais par le nom ou le type annoncé
 *     par le navigateur : le SVG, par exemple, est refusé ;
 *   - une image de 40 000 × 40 000 pixels (« bombe de décompression ») ferait exploser la mémoire du serveur : la taille en pixels
 *     est lue dans l'en-tête et contrôlée AVANT de décoder quoi que ce soit ;
 *   - la photo est recadrée en carré et réduite : ~40 Ko au lieu de plusieurs Mo, chargée vite partout.
 *
 * Nécessite l'extension PHP GD (déclarée dans composer.json).
 */
final class AvatarImageProcessor
{
    /** Taille maximale du fichier reçu (le navigateur réduit déjà la photo avant l'envoi : voir assets/account.js). */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    /** Taille maximale en pixels (largeur × hauteur) acceptée : 16 millions, soit une photo de téléphone de 4 000 × 4 000. */
    public const MAX_PIXELS = 16_000_000;
    /** Côté de la photo enregistrée. */
    public const OUTPUT_SIZE = 512;
    private const JPEG_QUALITY = 85;
    private const ALLOWED_TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP, \IMAGETYPE_GIF];

    /**
     * @return string octets d'un JPEG carré
     *
     * @throws AccountActionException « photo_required », « photo_too_large », « photo_unsupported », « photo_invalid »
     */
    public function process(string $bytes): string
    {
        if ('' === $bytes) {
            throw new AccountActionException('photo_required');
        }
        if (\strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new AccountActionException('photo_too_large');
        }

        // Lecture de l'EN-TÊTE seulement (type et dimensions) : rien n'est décodé tant que ces contrôles ne sont pas passés.
        $info = @getimagesizefromstring($bytes);
        if (false === $info || !\in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new AccountActionException('photo_unsupported');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new AccountActionException('photo_too_large');
        }

        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            throw new AccountActionException('photo_invalid');
        }

        try {
            if (\IMAGETYPE_JPEG === $info[2]) {
                $source = $this->orient($source, $bytes);
            }

            return $this->squareJpeg($source);
        } finally {
            imagedestroy($source);
        }
    }

    /** Recadre au centre en carré, réduit à 512 px au plus, aplatit la transparence sur fond blanc, écrit un JPEG. */
    private function squareJpeg(\GdImage $source): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $size = min(self::OUTPUT_SIZE, $side);

        $target = imagecreatetruecolor($size, $size);
        // Fond blanc : un PNG transparent ne doit pas devenir noir une fois converti en JPEG (qui n'a pas de transparence).
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);

        ob_start();
        imagejpeg($target, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($target);

        return $jpeg;
    }

    /**
     * Les photos de téléphone portent un « sens de prise de vue » (EXIF) au lieu d'être tournées : sans cette correction
     * un portrait s'afficherait couché. Les métadonnées elles-mêmes (dont la position GPS) disparaissent au ré-encodage.
     */
    private function orient(\GdImage $image, string $bytes): \GdImage
    {
        if (!\function_exists('exif_read_data')) {
            return $image;
        }
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            return $image;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = \is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate() tourne dans le sens inverse des aiguilles d'une montre.
        $result = match ($orientation) {
            2 => $this->flipped($image, \IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flipped($image, \IMG_FLIP_VERTICAL),
            5 => imagerotate($this->flipped($image, \IMG_FLIP_HORIZONTAL), 90, 0),
            6 => imagerotate($image, -90, 0),
            7 => imagerotate($this->flipped($image, \IMG_FLIP_HORIZONTAL), -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return false === $result ? $image : $result;
    }

    private function flipped(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode);

        return $image;
    }
}
