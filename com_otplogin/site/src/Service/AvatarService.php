<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Stores a square profile photo under images/otplogin/avatars and records the
 * relative path in #__user_profiles (key otplogin.avatar).
 */
final class AvatarService
{
    private const PROFILE_KEY = 'otplogin.avatar';
    private const MAX_BYTES   = 5_242_880; // 5 MB
    private const MAX_EDGE    = 640;
    private const ALLOWED     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private DatabaseInterface $db,
        private Registry $params
    ) {
    }

    public function enabled(): bool
    {
        return (int) $this->params->get('avatar_enabled', 1) === 1;
    }

    public function pathFor(int $userId): string
    {
        $val = (string) $this->db->setQuery(
            'SELECT `profile_value` FROM ' . $this->db->quoteName('#__user_profiles')
            . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $this->db->quote(self::PROFILE_KEY)
            . ' LIMIT 1'
        )->loadResult();

        return $val !== '' ? $val : '';
    }

    public function urlFor(int $userId): string
    {
        $path = $this->pathFor($userId);

        if ($path === '' || !is_file(JPATH_ROOT . '/' . $path)) {
            return '';
        }

        return Uri::root(true) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param  array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}  $file  from $_FILES
     * @return array{path?:string,url?:string,error?:string}
     */
    public function upload(int $userId, array $file): array
    {
        if (!$this->enabled()) {
            return ['error' => 'avatar_disabled'];
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'avatar_upload'];
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['error' => 'avatar_size'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        // Accept real HTTP uploads; also allow is_file when is_uploaded_file is overly strict on some hosts.
        if ($tmp === '' || !is_file($tmp)) {
            return ['error' => 'avatar_upload'];
        }
        if (!is_uploaded_file($tmp) && (int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['error' => 'avatar_upload'];
        }

        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        // Some phones send image/jpg
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        if (!$info || !isset(self::ALLOWED[$mime])) {
            return ['error' => 'avatar_type'];
        }

        // Always re-encode to JPEG via GD (strips EXIF payloads / polyglot malware).
        // File extension is fixed; original client filename is never used on disk.
        $dir = JPATH_ROOT . '/images/otplogin/avatars';
        $rel = 'images/otplogin/avatars/u' . $userId . '.jpg';

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['error' => 'avatar_save'];
        }

        // Remove previous files for this user (any extension).
        foreach (glob($dir . '/u' . $userId . '.*') ?: [] as $oldFile) {
            @unlink($oldFile);
        }

        $dest = JPATH_ROOT . '/' . $rel;

        // Require camera flag from client (defense in depth; still validates pixels server-side).
        // Missing flag is accepted only if image decodes cleanly through GD (below).

        if (!$this->resizeAndSaveAsJpeg($tmp, $dest, $mime)) {
            return ['error' => 'avatar_save'];
        }

        // Verify written file is a real JPEG and within size limits.
        if (!is_file($dest) || filesize($dest) < 100 || filesize($dest) > self::MAX_BYTES) {
            @unlink($dest);
            return ['error' => 'avatar_save'];
        }
        $check = @getimagesize($dest);
        if (!$check || ($check['mime'] ?? '') !== 'image/jpeg') {
            @unlink($dest);
            return ['error' => 'avatar_type'];
        }

        $this->setProfile($userId, $rel);
        $this->syncExternal($userId, $rel);

        return ['path' => $rel, 'url' => Uri::root(true) . '/' . $rel];
    }

    public function remove(int $userId): void
    {
        $path = $this->pathFor($userId);

        if ($path !== '' && is_file(JPATH_ROOT . '/' . $path)) {
            @unlink(JPATH_ROOT . '/' . $path);
        }

        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__user_profiles')
            . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $this->db->quote(self::PROFILE_KEY)
        )->execute();

        $this->syncExternal($userId, '');
    }

    private function setProfile(int $userId, string $value): void
    {
        $db = $this->db;
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__user_profiles')
            . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $db->quote(self::PROFILE_KEY)
        )->execute();
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__user_profiles')
            . ' (`user_id`, `profile_key`, `profile_value`, `ordering`) VALUES ('
            . $userId . ', ' . $db->quote(self::PROFILE_KEY) . ', ' . $db->quote($value) . ', 0)'
        )->execute();
    }


    /**
     * Decode allowed raster formats and write a clean JPEG only.
     * Original bytes are never stored — mitigates polyglot / embedded malware.
     */

    /**
     * Rotate/flip GD image according to EXIF Orientation (phone cameras).
     *
     * @param  \GdImage|resource  $img
     * @return \GdImage|resource
     */
    private function applyExifOrientation(string $src, $img)
    {
        if (!\function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($src);
        if (!\is_array($exif) || empty($exif['Orientation'])) {
            return $img;
        }

        $orientation = (int) $exif['Orientation'];

        switch ($orientation) {
            case 2: // mirror horizontal
                if (\function_exists('imageflip')) {
                    imageflip($img, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 3: // 180
                $rot = imagerotate($img, 180, 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
                break;
            case 4: // mirror vertical
                if (\function_exists('imageflip')) {
                    imageflip($img, IMG_FLIP_VERTICAL);
                }
                break;
            case 5: // mirror horizontal + 90 CCW
                if (\function_exists('imageflip')) {
                    imageflip($img, IMG_FLIP_HORIZONTAL);
                }
                $rot = imagerotate($img, 90, 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
                break;
            case 6: // 90 CW (most common for portrait phone shots)
                $rot = imagerotate($img, -90, 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
                break;
            case 7: // mirror horizontal + 90 CW
                if (\function_exists('imageflip')) {
                    imageflip($img, IMG_FLIP_HORIZONTAL);
                }
                $rot = imagerotate($img, -90, 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
                break;
            case 8: // 90 CCW
                $rot = imagerotate($img, 90, 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
                break;
            default:
                break;
        }

        return $img;
    }

    private function resizeAndSaveAsJpeg(string $src, string $dest, string $mime): bool
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            return false;
        }

        $head = (string) @file_get_contents($src, false, null, 0, 8192);
        if ($head === '' || preg_match('/<\?php|<\?=|<script[\s>]/i', $head)) {
            return false;
        }

        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/webp' => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default      => false,
        };

        if (!$img) {
            return false;
        }

        // Mobile cameras store rotation in EXIF; GD does not apply it automatically.
        if ($mime === 'image/jpeg') {
            $img = $this->applyExifOrientation($src, $img);
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if ($w < 32 || $h < 32 || $w > 8000 || $h > 8000) {
            imagedestroy($img);

            return false;
        }

        $side = min($w, $h);
        $sx   = (int) (($w - $side) / 2);
        $sy   = (int) (($h - $side) / 2);
        $edge = min(self::MAX_EDGE, $side);

        $out   = imagecreatetruecolor($edge, $edge);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $edge, $edge, $white);
        imagecopyresampled($out, $img, 0, 0, $sx, $sy, $edge, $edge, $side, $side);
        imagedestroy($img);

        $ok = imagejpeg($out, $dest, 90);
        imagedestroy($out);

        return (bool) $ok;
    }

    private function resizeAndSave(string $src, string $dest, string $mime): bool
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default      => false,
        };

        if (!$img) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $sx = (int) (($w - $side) / 2);
        $sy = (int) (($h - $side) / 2);
        $edge = min(self::MAX_EDGE, $side);

        $out = imagecreatetruecolor($edge, $edge);

        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }

        imagecopyresampled($out, $img, 0, 0, $sx, $sy, $edge, $edge, $side, $side);
        imagedestroy($img);

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($out, $dest, 92),
            'image/png'  => imagepng($out, $dest, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($out, $dest, 90) : false,
            default      => false,
        };

        imagedestroy($out);

        return (bool) $ok;
    }

    public function syncExternal(int $userId, string $relPath): void
    {
        try {
            $this->syncJoomlaProfile($userId, $relPath);
        } catch (\Throwable $e) {
        }
        try {
            $this->syncHikashop($userId, $relPath);
        } catch (\Throwable $e) {
        }
    }

    private function syncJoomlaProfile(int $userId, string $relPath): void
    {
        $db = $this->db;
        foreach (['profile.otplogin_avatar', 'profile.avatar'] as $key) {
            $db->setQuery(
                'DELETE FROM ' . $db->quoteName('#__user_profiles')
                . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $db->quote($key)
            )->execute();
            if ($relPath === '') {
                continue;
            }
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__user_profiles')
                . ' (`user_id`, `profile_key`, `profile_value`, `ordering`) VALUES ('
                . $userId . ', ' . $db->quote($key) . ', ' . $db->quote($relPath) . ', 50)'
            )->execute();
        }

        $fieldId = (int) $this->params->get('avatar_joomla_field_id', 0);
        if ($fieldId > 0) {
            $db->setQuery(
                'DELETE FROM ' . $db->quoteName('#__fields_values')
                . ' WHERE `field_id` = ' . $fieldId . ' AND `item_id` = ' . $userId
            )->execute();
            if ($relPath !== '') {
                $db->setQuery(
                    'INSERT INTO ' . $db->quoteName('#__fields_values')
                    . ' (`field_id`, `item_id`, `value`) VALUES ('
                    . $fieldId . ', ' . $userId . ', ' . $db->quote($relPath) . ')'
                )->execute();
            }
        }
    }

    private function syncHikashop(int $userId, string $relPath): void
    {
        $db = $this->db;
        $prefix = $db->getPrefix();
        $tables = array_map('strtolower', $db->setQuery('SHOW TABLES')->loadColumn() ?: []);
        $userTable = $prefix . 'hikashop_user';

        if (!\in_array(strtolower($userTable), $tables, true)) {
            return;
        }

        $hkId = (int) $db->setQuery(
            'SELECT `user_id` FROM ' . $db->quoteName($userTable)
            . ' WHERE `user_cms_id` = ' . $userId . ' LIMIT 1'
        )->loadResult();

        if ($hkId < 1) {
            return;
        }

        $fileName = '';
        if ($relPath !== '' && is_file(JPATH_ROOT . '/' . $relPath)) {
            $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION) ?: 'jpg');
            $fileName = 'otplogin_u' . $userId . '.' . $ext;
            foreach ([
                JPATH_ROOT . '/images/com_hikashop/upload',
                JPATH_ROOT . '/media/com_hikashop/upload',
                JPATH_ROOT . '/images/com_hikashop/upload/safe',
                JPATH_ROOT . '/media/com_hikashop/upload/safe',
            ] as $dir) {
                if (is_dir($dir) || @mkdir($dir, 0755, true)) {
                    @copy(JPATH_ROOT . '/' . $relPath, $dir . '/' . $fileName);
                }
            }
        }

        $fieldTable = $prefix . 'hikashop_field';
        if (\in_array(strtolower($fieldTable), $tables, true)) {
            $fields = $db->setQuery(
                'SELECT `field_name` FROM ' . $db->quoteName($fieldTable)
                . ' WHERE `field_table` = ' . $db->quote('user')
                . ' AND (`field_type` = ' . $db->quote('image')
                . ' OR `field_name` IN ('
                . $db->quote('avatar') . ',' . $db->quote('image') . ',' . $db->quote('picture')
                . ',' . $db->quote('user_image') . ',' . $db->quote('photo') . '))'
            )->loadColumn() ?: [];
            $cols = array_map('strtolower', $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($userTable))->loadColumn() ?: []);
            foreach ($fields as $name) {
                $name = (string) $name;
                if ($name === '' || !\in_array(strtolower($name), $cols, true)) {
                    continue;
                }
                $db->setQuery(
                    'UPDATE ' . $db->quoteName($userTable)
                    . ' SET ' . $db->quoteName($name) . ' = ' . $db->quote($fileName)
                    . ' WHERE `user_id` = ' . $hkId
                )->execute();
            }
        }

        $row = (string) $db->setQuery(
            'SELECT `user_params` FROM ' . $db->quoteName($userTable) . ' WHERE `user_id` = ' . $hkId
        )->loadResult();

        $paramsObj = new \stdClass();
        if ($row !== '') {
            $json = json_decode($row);
            if (\is_object($json)) {
                $paramsObj = $json;
            } else {
                $unser = @unserialize($row);
                if (\is_object($unser)) {
                    $paramsObj = $unser;
                }
            }
        }

        if ($fileName !== '') {
            $paramsObj->otplogin_avatar = $fileName;
            $paramsObj->avatar = $fileName;
        } else {
            unset($paramsObj->otplogin_avatar, $paramsObj->avatar);
        }

        $store = ($row !== '' && isset($row[0]) && $row[0] === '{')
            ? json_encode($paramsObj, JSON_UNESCAPED_UNICODE)
            : serialize($paramsObj);

        $db->setQuery(
            'UPDATE ' . $db->quoteName($userTable)
            . ' SET `user_params` = ' . $db->quote($store)
            . ' WHERE `user_id` = ' . $hkId
        )->execute();
    }
}
