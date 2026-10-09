package solosmartpay

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"strconv"
	"time"
)

// VerifyWebhook checks the X-SoloSmartPay-Signature / -Timestamp headers against
// the raw body and returns the event. Deliveries are at-least-once: dedupe on ID.
// A zero tolerance means 5 minutes.
func VerifyWebhook(rawBody []byte, signature, timestamp, secret string, tolerance time.Duration) (*WebhookEvent, error) {
	if secret == "" {
		return nil, &Error{Message: "webhook secret kosong"}
	}
	if tolerance <= 0 {
		tolerance = 5 * time.Minute
	}
	ts, err := strconv.ParseInt(timestamp, 10, 64)
	if err != nil || time.Since(time.Unix(ts, 0)).Abs() > tolerance {
		return nil, &Error{Message: "timestamp webhook tidak valid atau kedaluwarsa (kemungkinan replay)"}
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(timestamp + "." + string(rawBody)))
	expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return nil, &Error{Message: "signature webhook tidak valid"}
	}
	var ev WebhookEvent
	if err := json.Unmarshal(rawBody, &ev); err != nil || ev.ID == "" || ev.Type == "" {
		return nil, &Error{Message: "body webhook tidak valid"}
	}
	ev.Raw = append(json.RawMessage(nil), rawBody...)
	return &ev, nil
}
