from server.main import SlidingWindowRateLimiter, _validated_ip


def test_sliding_window_limiter_blocks_after_limit():
    limiter = SlidingWindowRateLimiter()
    assert limiter.hit("login:127.0.0.1", 2, 60) == 0
    assert limiter.hit("login:127.0.0.1", 2, 60) == 0
    assert limiter.hit("login:127.0.0.1", 2, 60) > 0


def test_limiter_reset_allows_new_attempt():
    limiter = SlidingWindowRateLimiter()
    limiter.hit("register:127.0.0.1", 1, 60)
    assert limiter.hit("register:127.0.0.1", 1, 60) > 0
    limiter.reset("register:127.0.0.1")
    assert limiter.hit("register:127.0.0.1", 1, 60) == 0


def test_ip_validation_rejects_spoofed_text():
    assert _validated_ip("203.0.113.7") == "203.0.113.7"
    assert _validated_ip("203.0.113.7:443") == "203.0.113.7"
    assert _validated_ip("not-an-ip") == ""
