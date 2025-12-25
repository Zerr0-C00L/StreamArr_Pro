package services

import (
    "strings"

    "github.com/Zerr0-C00L/StreamArr/internal/models"
)

// IsIndianMovie returns true if the movie appears to be from India
// Heuristics: production country includes "IN" or original language is Hindi ("hi")
func IsIndianMovie(m *models.Movie) bool {
    if m == nil { return false }
    if m.Metadata != nil {
        // Check production countries
        if pcs, ok := m.Metadata["production_countries"].([]string); ok {
            for _, c := range pcs { if strings.EqualFold(c, "IN") { return true } }
        } else if arr, ok := m.Metadata["production_countries"].([]interface{}); ok {
            for _, v := range arr {
                if s, ok2 := v.(string); ok2 && strings.EqualFold(s, "IN") { return true }
            }
        }
        // Check original language
        if ol, ok := m.Metadata["original_language"].(string); ok {
            if strings.EqualFold(ol, "hi") { return true }
        }
    }
    return false
}

// IsIndianSeries returns true if the series appears to be from India
// Heuristics: origin_country includes "IN" or original language is Hindi ("hi")
func IsIndianSeries(s *models.Series) bool {
    if s == nil { return false }
    if s.Metadata != nil {
        // origin_country can be []string
        if oc, ok := s.Metadata["origin_country"].([]string); ok {
            for _, c := range oc { if strings.EqualFold(c, "IN") { return true } }
        } else if arr, ok := s.Metadata["origin_country"].([]interface{}); ok {
            for _, v := range arr {
                if st, ok2 := v.(string); ok2 && strings.EqualFold(st, "IN") { return true }
            }
        }
        if ol, ok := s.Metadata["original_language"].(string); ok {
            if strings.EqualFold(ol, "hi") { return true }
        }
    }
    return false
}
