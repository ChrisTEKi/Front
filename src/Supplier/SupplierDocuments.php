<?php

namespace App\Supplier;

use App\Enum\SupplierDocumentKind;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Contrôle des pièces justificatives déposées à l'inscription d'un fournisseur (pièce d'identité valide, NIF / STAT).
 *
 * RÈGLES (appliquées ICI, côté serveur : le navigateur ne fait que les rappeler) :
 *   - la pièce d'identité est OBLIGATOIRE pour tout fournisseur ; le justificatif NIF / STAT l'est pour une entreprise
 *     (« activityType = company » dans le dossier), facultatif sinon ;
 *   - PDF, JPEG ou PNG seulement, 2 Mo au plus par fichier ; le type est déterminé en LISANT le contenu (un exécutable renommé
 *     en « .pdf » est refusé), jamais d'après le nom ou le type annoncé par le navigateur ;
 *   - le nom conservé est nettoyé (pas de chemin ni de caractère spécial) avec l'extension imposée par le type détecté.
 *
 * Ce service ne touche pas à la base : il rend les fichiers lus ou les erreurs, par champ ; RegistrationService les enregistre
 * dans la même transaction que le compte.
 */
final class SupplierDocuments
{
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> type MIME => extension */
    public const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * @param array<string, mixed> $uploads  fichiers reçus, par nom de champ (identityDocument, taxDocument) ; autre chose qu'un fichier = absent
     * @param bool                 $taxRequired vrai si le justificatif NIF / STAT est obligatoire (entreprise)
     *
     * @return array{documents: list<array{kind: SupplierDocumentKind, name: string, type: string, content: string}>, errors: array<string, array{reason: string, params: array<string, int>}>}
     */
    public function inspect(array $uploads, bool $taxRequired): array
    {
        $documents = [];
        $errors = [];
        foreach (SupplierDocumentKind::cases() as $kind) {
            $file = $uploads[$kind->field()] ?? null;
            $file = $file instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $file->getError() ? $file : null;
            if (null === $file) {
                if (SupplierDocumentKind::Identity === $kind || $taxRequired) {
                    $errors[$kind->field()] = ['reason' => 'doc_required', 'params' => []];
                }
                continue;
            }

            $read = $this->read($kind, $file);
            if (isset($read['error'])) {
                $errors[$kind->field()] = $read['error'];
            } else {
                $documents[] = $read;
            }
        }

        return ['documents' => $documents, 'errors' => $errors];
    }

    /**
     * @return array{kind: SupplierDocumentKind, name: string, type: string, content: string}|array{error: array{reason: string, params: array<string, int>}}
     */
    private function read(SupplierDocumentKind $kind, UploadedFile $file): array
    {
        $tooLarge = ['error' => ['reason' => 'doc_too_large', 'params' => ['%mb%' => (int) (self::MAX_FILE_BYTES / 1048576)]]];
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            return $tooLarge;
        }
        if (!$file->isValid()) {
            return ['error' => ['reason' => 'doc_invalid', 'params' => []]];
        }
        $size = (int) $file->getSize();
        if ($size <= 0) {
            return ['error' => ['reason' => 'doc_invalid', 'params' => []]];
        }
        if ($size > self::MAX_FILE_BYTES) {
            return $tooLarge;
        }
        $content = (string) file_get_contents($file->getPathname());
        $type = (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);
        if (!isset(self::ALLOWED[$type])) {
            return ['error' => ['reason' => 'doc_type', 'params' => []]];
        }

        return ['kind' => $kind, 'name' => $this->safeName($file->getClientOriginalName(), self::ALLOWED[$type], $kind), 'type' => $type, 'content' => $content];
    }

    /** Nom de fichier sans chemin, sans caractère spécial, de longueur raisonnable, avec l'extension imposée par le type détecté. */
    private function safeName(string $original, string $extension, SupplierDocumentKind $kind): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), \PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._() -]+/u', '_', $base), ' ._-');
        $base = mb_substr('' !== $base ? $base : (SupplierDocumentKind::Identity === $kind ? 'identite' : 'nif-stat'), 0, 100);

        return $base.'.'.$extension;
    }
}
