<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

use Vitalcare\Http\HttpError;

/**
 * The public course pages read the CMS (D-018). Published rows only, and only the owner-entered public
 * facts (Specs::PUBLIC_COURSE): no id, no draft state, no timestamps. An unpublished course is the same
 * 404 as one that does not exist.
 */
final class PublicCourses
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $st = $this->pdo->query('SELECT * FROM courses WHERE published = 1 ORDER BY sort_order, title, id');
        return array_map(self::shape(...), $st->fetchAll());
    }

    /** @return array<string,mixed> */
    public function get(string $slug): array
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80) {
            throw HttpError::notFound();
        }
        $st = $this->pdo->prepare('SELECT * FROM courses WHERE slug = ? AND published = 1');
        $st->execute([$slug]);
        $row = $st->fetch();
        if ($row === false) {
            throw HttpError::notFound();
        }
        return self::shape($row);
    }

    /** @param array<string,mixed> $row */
    private static function shape(array $row): array
    {
        $all = Record::out(Specs::COURSE, $row);
        $out = [];
        foreach (Specs::PUBLIC_COURSE as $key) {
            $out[$key] = $all[$key];
        }
        return $out;
    }
}
