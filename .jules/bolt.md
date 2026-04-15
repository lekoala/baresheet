## 2024-11-20 - Optimize ODS Text Extraction
**Learning:** In PHP's `XMLReader`, using `$reader->readString()` is significantly faster and more memory-efficient than creating a full DOM tree via `$reader->expand()->textContent` for extracting text content from elements. This is highly effective for reading large `.ods` spreadsheet files.
**Action:** Use `$reader->readString()` instead of `$reader->expand()->textContent` for extracting text from XML elements whenever possible.
## 2024-11-20 - Fast-path XML escaping
**Learning:** `strpbrk` is heavily optimized in C and uses a fast bitmask lookup. When escaping large volumes of strings for XML (like in spreadsheet exports), the vast majority of strings don't contain special characters (`&<>"'`) or control characters (`\x00-\x1F`). By combining all checks into a single `strpbrk` call, we can return early and completely bypass the overhead of `htmlspecialchars` and array-based `str_replace` for common plain-text strings. Since all searched characters are 7-bit ASCII (`< 128`), this byte-based search is completely safe for UTF-8 encoded text and won't trigger false positives on multi-byte characters.
**Action:** Use `strpbrk` to implement a fast-path early return for common string transformations like escaping, checking for both special characters and invalid control characters simultaneously.
## 2024-11-20 - Avoid closure overhead in tight loops
**Learning:** In high-iteration loops (e.g., writing 100,000+ rows to a CSV), invoking a closure or function on every iteration adds measurable overhead, even if the closure does nothing.
**Action:** When a loop applies optional transformations via a closure (like encoding or escaping), compute a boolean `$needsProcessing` flag beforehand and use it to completely bypass the closure call when no transformations are required. This fast-path optimization yields ~30% write time reductions in plain-text CSV generation.
## 2026-04-02 - Optimize mb_strlen in tight loops
**Learning:** In performance-critical loops writing cell values, `mb_strlen()` is significantly slower than `strlen()`. However, blindly replacing it can cause regressions, such as breaking Excel column auto-sizing for multi-byte characters.
**Action:** Use `strlen()` for byte-length threshold checks (like limiting shared strings to <= 160 bytes), but conditionally retain `mb_strlen()` only when its result is strictly required (like when `autoWidth` is actively enabled for the column).
## 2024-05-18 - Optimize array iteration with foreach and references
**Learning:** In PHP, when mapping over a high-iteration array, using `array_map` with a closure introduces significant overhead due to the repeated function call.
**Action:** Replace `array_map(fn($v) => ...)` with a `foreach` loop that modifies elements by reference `foreach ($arr as &$v)` and then unsets the reference `unset($v)`. This yields measurable performance improvements over large arrays by completely bypassing per-element function call overhead.
## 2026-04-09 - Avoid closure overhead in high-iteration XML loops
**Learning:** In PHP, defining and calling a closure (e.g., `$isDate = function(...) use (...) {...}`) within a deeply nested parsing loop (like iterating through tens of thousands of cells in an Excel worksheet) introduces measurable overhead.
**Action:** Inline the logic and caching mechanisms directly into the loop instead of wrapping them in a closure. This bypasses the function call overhead on every iteration and provides a measurable speedup for parsing large documents.
## 2024-05-20 - Fast-path Excel column string length parsing
**Learning:** `Spread::columnIndex(string $letter): int` was previously parsing characters using a loop: `for ($i = 0; $i < $length; $i++) { $index = $index * 26 + (ord(strtoupper($letter[$i])) - 64); }`. This parsing logic occurs inside tight inner loops for cell column parsing in `XlsxReader`.
**Action:** Unroll lengths 1 to 3 since valid Excel column names max out at length 3 (up to "XFD"). Return early to bypass `strlen()`, `ord()`, and loop bounds checking iterations, increasing reading speed for the Baresheet XLSX parser by approximately ~5%.
## 2024-05-20 - Favor clean caching over unrolling
**Learning:** While unrolling parsing loops for Excel column letters provided a slight performance bump in microbenchmarks, the resulting code was messy and harder to maintain. A simpler, more readable approach is to memoize results using a `static` array cache, which yields even better performance for repeated calls (like parsing the same columns across thousands of rows) without sacrificing code clarity.
**Action:** Use a `static $cache = []` to memoize the results of `Spread::columnIndex(string $letter)` instead of manually unrolling the string parsing loop.
