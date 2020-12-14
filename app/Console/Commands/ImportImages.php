<?php

namespace App\Console\Commands;

use App\Models\Seller;
use League\Csv\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Http, Storage};
use Illuminate\Http\File;

class ImportImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:import
        {csvPath : The path to the CSV file}
        {--headers= : Optional headers to use from the CSV file}
        {--dryRun : Should this be a dry run}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import and resize images for a seller CSV file.';

    protected $bar;

    protected $seller;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $sellerName = $this->choice('Which seller is this for', Seller::pluck('name')->toArray());
        $this->seller = Seller::where('name', $sellerName)->first();

        $filePath = $this->argument('csvPath');

        try {
            $csv = Reader::createFromPath($filePath, 'r');
        } catch (\Exception $e) {
            $this->error('Unable to open file.');

            return 0;
        }

        $csv->setHeaderOffset(0);
        // perform header check
        $headers = $this->option('headers') ?? ['Image 1', 'Image 2', 'Image 3', 'Image 4'];
        // $header = $csv->getHeader();
        // $this->info($header);
        $records = $csv->getRecords(); //returns all the CSV records as an Iterator object

        foreach ($records as $index => $record) {
            $this->info(sprintf('Retrieving row: %s images...', $index));
            $record = collect($record);
            $urls = $record->filter(function ($item, $index) use ($headers) {
                return in_array($index, $headers);
            });
            $this->fetchImagesFromUrls($urls);
        }

        return 0;
    }

    public function fetchImagesFromUrls(Collection $urls)
    {
        $good = [];
        $bad = [];

        $this->bar = $this->output->createProgressBar($urls->count());

        foreach ($urls as $url) {
            $nameParts = explode("/", $url);
            $name = end($nameParts);
            $exists = $this->seller->media()->where('file_name', $name)->exists();

            if (! empty($url) && ! $exists) {
                try {
                    $httpResponse = Http::get($url);
                    $responseBody = $httpResponse->body();

                    $good[] = $this->seller
                        ->addMediaFromString($responseBody)
                        ->usingFileName($name)
                        ->toMediaCollection('bulk-upload-images');
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    // $this->error(sprintf('Unable to fetch image %s', $url));
                    $bad[] = $url;
                }
            }

            $this->bar->advance();
        }


        $this->bar->finish();

        $this->info('');
    }
}
