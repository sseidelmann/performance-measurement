# Performance Measurement

## Usage:

```
bin/perf --config="./performance.yml"
```

### Example Output

```
Start profile for www.heise.de
  requests: 3
  urls:     3

...

/
  median: 1.455 sec (0.932 sec)
  min:    1.241 sec (0.791 sec)
  max:    1.811 sec (1.007 sec)
/newsticker/classic/
  median: 1.138 sec (0.936 sec)
  min:    0.82 sec (0.551 sec)
  max:    1.38 sec (1.234 sec)
/forum/startseite/
  median: 0.705 sec (0.482 sec)
  min:    0.517 sec (0.431 sec)
  max:    0.878 sec (0.565 sec)
```

## Configuration file

```
base: http://www.heise.de/
pages:
  - /
  - /newsticker/classic/
  - /forum/startseite/
requests: 3
```
