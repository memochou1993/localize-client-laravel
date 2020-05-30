<?php

namespace MemoChou\Localize;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\VarExporter\Exception\ExceptionInterface;
use Symfony\Component\VarExporter\VarExporter;

class Localize
{
    /**
     * @var array
     */
    protected array $project;

    /**
     * @var Collection|null
     */
    protected ?Collection $expectedLanguages = null;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->fetch();
    }

    /**
     * @return string
     */
    protected function host(): string
    {
        return config('localize.host');
    }

    /**
     * @return string
     */
    protected function projectId(): string
    {
        return config('localize.project_id');
    }

    /**
     * @return string
     */
    protected function url(): string
    {
        return '/api/client/projects/'.$this->projectId();
    }

    /**
     * @return string
     */
    protected function secretKey(): string
    {
        return config('localize.secret_key');
    }

    /**
     * @return string
     */
    protected function directory(): string
    {
        return config('localize.directory');
    }

    /**
     * @return string
     */
    protected function filename(): string
    {
        return config('localize.filename');
    }

    /**
     * @return array
     */
    protected function headers(): array
    {
        return [
            'X-Localize-Secret-Key' => $this->secretKey(),
        ];
    }

    /**
     * @param array $project
     * @return void
     */
    protected function setProject($project): void
    {
        $this->project = $project;
    }

    /**
     * @return Collection
     */
    protected function getKeys(): Collection
    {
        return collect($this->project['keys']);
    }

    /**
     * @return Collection
     */
    public function getLanguages(): Collection
    {
        return collect($this->project['languages'])->pluck('name');
    }

    /**
     * @return Collection
     */
    protected function getExpectedLanguages(): Collection
    {
        return collect($this->expectedLanguages)->whenEmpty(function () {
            return $this->getLanguages();
        });
    }

    /**
     * @param mixed $language
     * @return bool
     */
    public function hasLanguage($language): bool
    {
        return $this->getLanguages()->contains($language);
    }

    /**
     * @param mixed $language
     * @return bool
     */
    protected function hasExpectedLanguage($language): bool
    {
        return $this->getExpectedLanguages()->contains($language);
    }

    /**
     * @return void
     */
    protected function fetch(): void
    {
        $response = Http::retry(3, 500)
            ->baseUrl($this->host())
            ->withHeaders($this->headers())
            ->get($this->url());

        // TODO: throw exception
        // $response->throw();

        $data = json_decode($response->body(), true);

        $this->setProject($data['data']);
    }

    /**
     * @param string $language
     * @return array
     */
    protected function formatKeys(string $language): array
    {
        return $this->getKeys()
            ->mapWithKeys(function ($key) use ($language) {
                return [
                    $key['name'] => $this->formatValues($key['values'], $language),
                ];
            })
            ->toArray();
    }

    /**
     * @param array $values
     * @param string $language
     * @return string
     */
    protected function formatValues(array $values, string $language): string
    {
        return collect($values)
            ->filter(function ($value) use ($language) {
                return $value['language']['name'] === $language;
            })
            ->map(function ($value) {
                return vsprintf('%s%s%s%s%s%s', [
                    '[',
                    $value['form']['range_min'],
                    ',',
                    $value['form']['range_max'],
                    ']',
                    $value['text'],
                ]);
            })
            ->implode('|');
    }

    /**
     * @param array|string $languages
     * @return self
     */
    public function only(...$languages): self
    {
        $this->expectedLanguages = collect($languages)
            ->flatten()
            ->intersect($this->getLanguages());

        return $this;
    }

    /**
     * @param array|string $languages
     * @return self
     */
    public function except(...$languages): self
    {
        $this->expectedLanguages = $this->getLanguages()
            ->diff(collect($languages)->flatten());

        return $this;
    }

    /**
     * @return self
     */
    public function export(): self
    {
        $this->getExpectedLanguages()
            ->each(function ($language) {
                $this->save($language);
            });

        return $this;
    }

    /**
     * @param string $language
     * @return void
     * @throws ExceptionInterface
     */
    protected function save(string $language): void
    {
        $keys = $this->formatKeys($language);

        $data = vsprintf('%s%s%s%s%s%s%s', [
            '<?php',
            PHP_EOL,
            PHP_EOL,
            'return ',
            VarExporter::export($keys),
            ';',
            PHP_EOL,
        ]);

        $directory = vsprintf('%s/%s', [
            $this->directory(),
            $language,
        ]);

        File::ensureDirectoryExists($directory);

        $filename = vsprintf('%s/%s.php', [
            $directory,
            $this->filename(),
        ]);

        file_put_contents($filename, $data);
    }

    /**
     * @return self
     */
    public function clear(): self
    {
        $directories = File::directories($this->directory());

        collect($directories)
            ->reject(function ($directory) {
                return $this->hasExpectedLanguage(basename($directory));
            })
            ->each(function ($directory) {
                File::deleteDirectory($directory);
            });

        return $this;
    }
}