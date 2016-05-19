<?php
/**
 * Class Meter
 * @package Sseidelmann\PerformanceMeasurement
 * @author Sebastian Seidelmann <sebastian.seidelmann@googlemail.com>
 */

namespace Sseidelmann\PerformanceMeasurement;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Ulrichsg\Getopt\Getopt;
use Ulrichsg\Getopt\Option;

/**
 * Class Meter
 * @package Sseidelmann\PerformanceMeasurement
 * @author Sebastian Seidelmann <sebastian.seidelmann@googlemail.com>
 */
class Meter
{
    /**
     * Defines the version
     * @var int
     */
    const VERSION = '1.0';

    /**
     * Saves the getopt instance.
     * @var Getopt
     */
    private $getopt;

    /**
     * Saves the options.
     * @var array
     */
    private $options = array();

    /**
     * Saves the configuration.
     * @var array
     */
    private $configuration = array();

    /**
     * Constructs the Measurement
     */
    public function __construct()
    {
        $this->getopt   = new Getopt(array(
            new Option(null, 'config', Getopt::REQUIRED_ARGUMENT),
            new Option(null, 'version')
        ));
        $this->getopt->setBanner(implode(PHP_EOL, array(
                "\033[0;32mPerformanceMeasurement\033[0m version " . self::VERSION,
                "\033[0;31m%s\033[0m",
                "Usage: perf [options]"
            )) . PHP_EOL);
    }

    /**
     * Parse the arguments.
     * @return array
     */
    private function parseArguments()
    {
        try {
            $this->getopt->parse();
            $options = $this->getopt->getOptions();

            if (isset($options['version'])) {
                $this->showBanner();
            }

            if (!isset($options['config'])) {
                throw new \Exception('Option \'config\' must be given');
            }

            if (!file_exists($options['config'])) {
                throw new \Exception('Option \'config\' must be a valid file');
            }

            $this->options = $options;
        } catch (\Exception $exception) {
            $this->showBanner($exception->getMessage());
        }
    }

    /**
     * Display the banner and exit the application.
     * @param string $failure
     * @return void
     */
    private function showBanner($failure = null)
    {
        if (null === $failure) {
            echo $this->getopt->getBanner() . PHP_EOL;
        } else {
            echo sprintf($this->getopt->getBanner(), PHP_EOL . $failure . PHP_EOL);
        }

        exit(0);
    }

    /**
     * Setup the measurement
     * @return void
     */
    private function setup()
    {
        $this->parseArguments();

        try {
            $this->configuration = Yaml::parse(file_get_contents($this->options['config']));
        } catch (ParseException $exception) {
            $this->showBanner($exception->getMessage());
        }
    }

    /**
     * Starts the measurements.
     * @return void
     */
    public function startMeasurement()
    {
        $this->setup();

        echo "Start profile for \033[1;33m" . $this->getParsedUrlHost($this->getBaseUrl()) . "\033[0m" . PHP_EOL;
        echo "  requests: \033[1;32m" . $this->getNumberOfRequests() . "\033[0m" . PHP_EOL;
        echo "  urls:     \033[1;32m" . count($this->getPages()) . "\033[0m" . PHP_EOL . PHP_EOL;


        $measurements = array();
        foreach ($this->getPages() as $page => $url) {
            echo ".";
            $measurements[$page] = $this->measure($url);
        }
        echo PHP_EOL;

        $this->printReport($measurements);
    }

    private function printReport(array $measurements)
    {
        echo PHP_EOL;
        foreach ($measurements as $url => $measurement) {
            $body   = $measurement['body'];
            $header = $measurement['header'];
            echo "\033[1;34m" . $url . "\033[0m" . PHP_EOL;

            echo sprintf('  median: %s (%s)', $this->formatTime($body['median']), $this->formatTime($header['median'])) . PHP_EOL;
            echo sprintf('  min:    %s (%s)', $this->formatTime($body['min']), $this->formatTime($header['min'])) . PHP_EOL;
            echo sprintf('  max:    %s (%s)', $this->formatTime($body['max']), $this->formatTime($header['max'])) . PHP_EOL;
        }
    }


    private function formatTime($time)
    {
        return round($time, 3) . ' sec';
    }

    /**
     * returns the configuration.
     * @return array
     */
    private function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Returns the base url.
     * @return string
     */
    private function getBaseUrl()
    {
        return trim($this->configuration['base'], '/') . '/';
    }

    /**
     * Returns the host for the url.
     * @param string $url
     * @return string
     */
    private function getParsedUrlHost($url)
    {
        $parsed = parse_url($url);
        return $parsed['host'];
    }

    /**
     * Returns the pages.
     * @return array
     */
    private function getPages()
    {
        $pages = array();
        $base  = $this->getBaseUrl();
        foreach ($this->configuration['pages'] as $page) {
            $slug = ($page{0} == '/') ? substr($page, 1) : $page;

            $pages[$page] = $base . $slug;
        }

        return $pages;
    }

    /**
     * Returns the amount of requests per url.
     * @return int
     */
    private function getNumberOfRequests()
    {
        return $this->configuration['requests'];
    }


    private function measure($url)
    {
        return array(
            'body' => $this->doMeasurement($url, false),
            'header' => $this->doMeasurement($url, true)
        );
    }

    private function doMeasurement($url, $onlyHeader = false)
    {
        $measurements = array();

        for ($i = 0; $i < $this->getNumberOfRequests(); $i++) {

            $uniqueUrl    = $this->makeUniqueUrl($url);
            $curlHandle   = curl_init($uniqueUrl);

            curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, 0);

            if ($onlyHeader) {
                curl_setopt($curlHandle, CURLOPT_FILETIME, true);
                curl_setopt($curlHandle, CURLOPT_NOBODY, true);
                curl_setopt($curlHandle, CURLOPT_HEADER, true);
            }

            if (curl_exec($curlHandle)) {
                $info = curl_getinfo($curlHandle);
                $measurements['requests'][$uniqueUrl] = $info['total_time'];
            }
            curl_close($curlHandle);
        }

        $measurements['median'] = array_sum($measurements['requests']) / count($measurements['requests']);
        $measurements['max']    = max($measurements['requests']);
        $measurements['min']    = min($measurements['requests']);

        return $measurements;
    }


    private function makeUniqueUrl($url)
    {
        $delimiter = (strpos($url, '?') !== false) ? '&' : '?';

        return $url . $delimiter . 'uniqueRequest=' . md5(microtime(true) . $url . uniqid());
    }
}