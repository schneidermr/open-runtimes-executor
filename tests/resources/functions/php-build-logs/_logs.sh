CHARS_128="11111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111"
CHARS_1KB="$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128"
CHARS_16KB="$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB$CHARS_1KB"
CHARS_128KB="$CHARS_16KB$CHARS_16KB$CHARS_16KB$CHARS_16KB$CHARS_16KB$CHARS_16KB$CHARS_16KB$CHARS_16KB"

# 7 * 128KB
echo -n "$CHARS_128KB"
echo -n "$CHARS_128KB"
echo -n "$CHARS_128KB"
echo -n "$CHARS_128KB"
echo -n "$CHARS_128KB"
echo -n "$CHARS_128KB"
echo -n "$CHARS_128KB"
