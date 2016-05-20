<?php
/**
 * Class Meter
 * @package Sseidelmann\PerformanceMeasurement
 * @author Sebastian Seidelmann <sebastian.seidelmann@googlemail.com>
 */

namespace Sseidelmann\PerformanceMeasurement;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
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
     * Defines the delimiter for CSV files.
     * @var string
     */
    const REPORT_CSV_DELIMITER = ';';

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
     * Saves the output formatter.
     * @var OutputFormatter
     */
    private $formatter;

    /**
     * Defines if the report file is only temp.
     * @var bool
     */
    private $reportTempFile = false;

    /**
     * Constructs the Measurement
     */
    public function __construct()
    {
        $this->getopt = new Getopt(array(
            new Option(null, 'config', Getopt::REQUIRED_ARGUMENT),
            new Option(null, 'report', Getopt::REQUIRED_ARGUMENT),
            new Option(null, 'ttfb'),
            new Option('v',  'version'),
            new Option('h',  'help')
        ));
    }

    /**
     * Shows the usage.
     * @return void
     */
    private function showUsage()
    {
        $this->writeln(array(
            '',
            '<info>Usage:</info>',
            '  perf [options]',
            '',
            '<info>Options:</info>',
            '  -v --version           <comment>Shows version</comment>',
            '  -h --help              <comment>Shows this help</comment>',
            '  --config=FILE          <comment>Defines the config file to use</comment>',
            '  --report=FILE          <comment>Save path for the report file</comment>',
            '  --ttfb                 <comment>Display the "time to first byte"</comment>'
        ));

        exit(0);
    }

    /**
     * Shows the version
     * @return void
     */
    private function showVersion()
    {
        $this->writeln();
        $this->writeln(sprintf('<info>perf</info> version <comment>%s</comment>', self::VERSION));

        exit(0);
    }

    /**
     * Shows a failure.
     * @param \Exception $exception
     * @return void
     */
    private function showFailure(\Exception $exception)
    {
        $this->writeln();
        $this->writeln(sprintf('<error> Error: %s </error>', $exception->getMessage()));
        $this->showUsage();
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
                $this->showVersion();
            }

            if (isset($options['help'])) {
                $this->showUsage();
            }

            if (!isset($options['config'])) {
                throw new \Exception('Option \'config\' must be given');
            }

            if (!file_exists($options['config'])) {
                throw new \Exception('Option \'config\' must be a valid file');
            }

            $this->options = $options;
        } catch (\Exception $exception) {
            $this->showFailure($exception);
        }
    }

    /**
     * Initialize the output formatter.
     * @return void
     */
    private function initializeOutputFormatter()
    {
        $this->formatter = new OutputFormatter(true, $this->getOutputFormatterStyles());
    }

    /**
     * Initialize the report file.
     * @return void
     */
    private function initializeReportFile()
    {
        if (!isset($this->options['report'])) {
            $this->options['report'] = tempnam('/tmp/', 'report');
            $this->reportTempFile = true;
        }

        file_put_contents($this->options['report'], '');
    }

    /**
     * Removes the report file if only tmp.
     * @return void
     */
    private function tearDownReportFile()
    {
        if ($this->reportTempFile) {
            unlink($this->reportTempFile);
        }
    }

    /**
     * Returns the output formatter styles.
     * @return OutputFormatterStyle[]
     */
    private function getOutputFormatterStyles()
    {
        return array(
            'head'    => new OutputFormatterStyle('cyan'),
            'success' => new OutputFormatterStyle('green')
        );
    }

    /**
     * Setup the measurement
     * @return void
     */
    private function setUp()
    {
        $this->initializeOutputFormatter();
        $this->parseArguments();
        $this->initializeReportFile();

        try {
            $this->configuration = Yaml::parse(file_get_contents($this->options['config']));
        } catch (ParseException $exception) {
            $this->showBanner($exception->getMessage());
        }
    }

    /**
     * Tear down the application.
     * @return void
     */
    private function tearDown()
    {
        $this->tearDownReportFile();
    }

    /**
     * Starts the measurements.
     * @return void
     */
    public function startMeasurement()
    {
        $this->setUp();

        $this->printMeasureHeader();

        $this->writeReport($this->measurePages($this->getPages()));
        $this->printReport();

        $this->tearDown();
    }

    /**
     * Prints the measurement header.
     * @return void
     */
    private function printMeasureHeader()
    {
        $this->writeln(sprintf('Start profile for <info>%s</info>', $this->getParsedUrlHost($this->getBaseUrl())));
        $this->writeln(sprintf('   requests: <comment>%s</comment>', $this->getNumberOfRequests()));
        $this->writeln(sprintf('   urls:     <comment>%s</comment>', count($this->getPages())));
    }

    /**
     * Measure the pages.
     * @param array $pages
     * @return array
     */
    private function measurePages(array $pages)
    {
        $measurements = array();
        foreach ($pages as $page => $url) {
            $measurements[$page] = $this->measure($url);
        }
        $this->writeln();
        $this->writeln();

        return $measurements;
    }

    /**
     * Prints the report.
     * @return void
     */
    private function printReport()
    {
        $contents = file_get_contents($this->getReportFile());
        $lines    = explode(PHP_EOL, $contents);


        $table = new Table(new ConsoleOutput());
        $table->setHeaders(str_getcsv(array_shift($lines), ';', '"'));
        foreach ($lines as $line) {
            $table->addRow(str_getcsv($line, ';', '"'));
        }
        $table->render();
    }

    /**
     * Writes the report file.
     * @param array $measurements
     * @return void
     */
    private function writeReport(array $measurements)
    {
        $mapping = array(
            'average'          => 'body_avg',
            'median'           => 'body_median',
            'min'              => 'body_min',
            'max'              => 'body_max',
            'requests per sec' => 'body_rps',
            'average (head)'          => 'header_avg',
            'median (head)'           => 'header_median',
            'min (head)'              => 'header_min',
            'max (head)'              => 'header_max',
            'requests per sec (head)' => 'header_rps',
        );

        if (isset($this->options['ttfb'])) {
            $mapping = array_merge($mapping, array(
                '[ttfb] average'          => 'body_ttfb_avg',
                '[ttfb] median'           => 'body_ttfb_median',
                '[ttfb] min'              => 'body_ttfb_min',
                '[ttfb] max'              => 'body_ttfb_max',
                '[ttfb] requests per sec' => 'body_ttfb_rps',
                '[ttfb] average (head)'          => 'header_ttfb_avg',
                '[ttfb] median (head)'           => 'header_ttfb_median',
                '[ttfb] min (head)'              => 'header_ttfb_min',
                '[ttfb] max (head)'              => 'header_ttfb_max',
                '[ttfb] requests per sec (head)' => 'header_ttfb_rps',
            ));
        }

        $this->writeln(sprintf('Write the report to <comment>%s</comment>', $this->getReportFile()));
        $this->writeReportHeader(array_merge(array('url', 'status', 'type'), array_keys($mapping), array('failure')));

        foreach ($measurements as $url => $measurement) {
            $body   = $measurement['body'];
            $header = $measurement['header'];

            $m = array();
            foreach ($measurement as $measurementKey => $measurementPart) {
                foreach ($body as $key => $value) {
                    $m[$measurementKey . '_' . $key] = $value;
                }
            }


            if (count($body) == 0 || count($header) == 0) {
                $this->writeReportln(
                    array_merge(
                        array(
                            $url,
                            '',
                            ''
                        ),
                        array_fill(
                            3,
                            count($mapping),
                            ''
                        ),
                        array(
                            true
                        )
                    )
                );
            } else {
                $this->writeReportln(array_merge(
                    array(
                        $url,
                        $measurement['body']['info']['http_code'],
                        $measurement['body']['info']['content_type']
                    ),
                    array_map(
                        function ($key) use ($m) {
                            return $m[$key];
                        },
                        array_values($mapping)
                    ),
                    array(
                        false
                    )
                ));
            }
        }
    }

    /**
     * Writes the report header.
     * @param array $header
     * @return void
     */
    private function writeReportHeader(array $header)
    {
        file_put_contents($this->getReportFile(), $this->prepareCsvValues($header), FILE_APPEND);
    }

    /**
     * Writes the report line.
     * @param array $values
     * @return void
     */
    private function writeReportln(array $values)
    {
        file_put_contents($this->getReportFile(), PHP_EOL . $this->prepareCsvValues($values), FILE_APPEND);
    }

    /**
     * Prepares the values.
     * @param array $values
     * @return string
     */
    private function prepareCsvValues(array $values)
    {
        return implode(
            self::REPORT_CSV_DELIMITER,
            array_map(
                function ($value) {
                    if (is_numeric($value)) {
                        return $value;
                    }
                    if (is_bool($value)) {
                        return $value ? 1 : '';
                    }
                    return sprintf('"%s"', str_replace('"', '\'', $value));
                },
                $values
            )
        );
    }

    /**
     * Writes the line to std out.
     * @param string|array $line
     * @return void
     */
    private function writeln($line = '')
    {
        if (is_array($line)) {
            foreach ($line as $l) {
                $this->writeln($l);
            }
        } else {
            $this->write($line . PHP_EOL);
        }
    }

    /**
     * Writes a line.
     * @param string $line
     * @return void
     */
    private function write($line = '')
    {
        echo $this->formatter->format($line);
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

    /**
     * Returns the report file.
     * @return string
     */
    private function getReportFile()
    {
        return $this->options['report'];
    }

    /**
     * Measures one URL.
     * @param string $url
     * @return array
     */
    private function measure($url)
    {
        return array(
            'body'   => $this->doMeasurement($url, false),
            'header' => $this->doMeasurement($url, true)
        );
    }

    /**
     * Prints a step.
     * @return void
     */
    private function printMeasureStep($failure = false)
    {
        if ($failure) {
            $this->write('<error>F</error>');
        } else {
            $this->write('<success>.</success>');
        }
    }

    /**
     * Do the measurement.
     * @param string $url
     * @param bool   $onlyHeader
     * @return array|bool
     */
    private function doMeasurement($url, $onlyHeader = false)
    {
        $measurements = array();
        $urlInfo      = array();

        for ($i = 0; $i < $this->getNumberOfRequests(); $i++) {
            $failure      = true;
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
                $measurements['ttfb'][$uniqueUrl]     = $info['starttransfer_time'];
                $measurements['requests'][$uniqueUrl] = $info['total_time'];
                $failure                              = false;

                if (count($urlInfo) == 0) {
                    $urlInfo = array(
                        'http_code'    => $info['http_code'],
                        'content_type' => $info['content_type']
                    );
                }
            }

            $this->printMeasureStep($failure);

            curl_close($curlHandle);
        }

        if (!is_array($measurements['requests']) || count($measurements['requests']) == 0) {
            return array();
        }

        asort($measurements['requests']);

        $measurements['total']       = array_sum($measurements['requests']);
        $measurements['median']      = $this->calculateMedian($measurements['requests']);
        $measurements['avg']         = $measurements['total'] / count($measurements['requests']);
        $measurements['max']         = max($measurements['requests']);
        $measurements['min']         = min($measurements['requests']);
        $measurements['rps']         = round(count($measurements['requests']) / $measurements['total'], 2);
        $measurements['ttfb_total']  = array_sum($measurements['ttfb']);
        $measurements['ttfb_median'] = $this->calculateMedian($measurements['ttfb']);
        $measurements['ttfb_avg']    = $measurements['ttfb_total'] / count($measurements['ttfb']);
        $measurements['ttfb_max']    = max($measurements['ttfb']);
        $measurements['ttfb_min']    = min($measurements['ttfb']);
        $measurements['ttfb_rps']    = round(count($measurements['ttfb']) / $measurements['ttfb_total'], 2);
        $measurements['info']        = $urlInfo;

        return $measurements;
    }

    /**
     * Calc the median.
     * @param array $requestTimes
     * @return float
     */
    private function calculateMedian(array $requestTimes)
    {
        $requestTimes = array_values($requestTimes);
        $requestCount = count($requestTimes);

        if ($requestCount % 2 == 0) {
            // count is even -> arithmetic operation of two center values
            $median = array_sum(array_slice($requestTimes, ($requestCount / 2 - 1), 2)) / 2;
        } else {
            if (count($requestTimes) == 1) {
                $median = current($requestTimes);
            } else {
                $center = ($requestCount - 1) / 2;
                $median = $requestTimes[$center];
            }
        }

        return $median;
    }

    /**
     * Makes the unique URL.
     * @param string $url
     * @return string
     */
    private function makeUniqueUrl($url)
    {
        $delimiter = (strpos($url, '?') !== false) ? '&' : '?';

        return $url . $delimiter . 'uniqueRequest=' . md5(microtime(true) . $url . uniqid());
    }
}