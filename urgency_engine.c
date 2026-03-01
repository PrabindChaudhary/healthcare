/*
 * ============================================================
 *  MediCare AI – Patient Urgency Prioritization Engine (C)
 *  File:    urgency_engine.c
 *  Author:  Project By Prabind
 *
 *  Compile: gcc urgency_engine.c -o urgency_engine
 *  Usage:   ./urgency_engine <severity 1-10> [symptom words...]
 *           echo "chest pain" | ./urgency_engine 9
 *  Stdout:  CRITICAL | HIGH | MEDIUM | LOW
 *
 *  Bug fixes:
 *   1. atoi() → strtol() with endptr validation
 *   2. Severity formula: was 0.5+factor*0.5 (min 0.55x at sev=1)
 *      now factor = severity/10.0  (min 0.10x at sev=1)
 *   3. Buffer overflow: strncat loop tracks remaining bytes
 *   4. Windows CRLF stripped from stdin (\r before \n)
 *   5. NULL/empty symptom guard avoids strncpy(NULL)
 *   6. Enum names prefixed URGENCY_ to avoid global name clashes
 *   7. Sentinel NULL entry is the only stopper; removed redundant
 *      loop bound check that could read past array end
 *   8. levelToString: explicit LOW case (not hidden in default)
 * ============================================================
 */

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>


/* ── Constants ─────────────────────────────────────────────── */
#define MAX_SYMPTOM_LEN 512

/* ── Urgency level enum ─────────────────────────────────────── */
typedef enum {
  URGENCY_LOW = 0,
  URGENCY_MEDIUM = 1,
  URGENCY_HIGH = 2,
  URGENCY_CRITICAL = 3
} UrgencyLevel;

/* ── Symptom lookup table ───────────────────────────────────── */
typedef struct {
  const char *keyword; /* lowercase, space-padded by matching logic */
  UrgencyLevel level;
  double weight;
} SymptomEntry;

/* Table must be terminated with a {NULL, …} sentinel */
static const SymptomEntry SYMPTOM_TABLE[] = {
    /* ---- CRITICAL ------------------------------------------ */
    {"chest pain", URGENCY_CRITICAL, 10.0},
    {"heart attack", URGENCY_CRITICAL, 10.0},
    {"stroke", URGENCY_CRITICAL, 10.0},
    {"unconscious", URGENCY_CRITICAL, 10.0},
    {"severe bleeding", URGENCY_CRITICAL, 9.5},
    {"cannot breathe", URGENCY_CRITICAL, 9.5},
    {"can't breathe", URGENCY_CRITICAL, 9.5},
    {"anaphylaxis", URGENCY_CRITICAL, 9.5},
    {"meningitis", URGENCY_CRITICAL, 9.0},
    {"severe head injury", URGENCY_CRITICAL, 9.0},

    /* ---- HIGH ---------------------------------------------- */
    {"high fever", URGENCY_HIGH, 7.5},
    {"shortness of breath", URGENCY_HIGH, 7.5},
    {"severe headache", URGENCY_HIGH, 7.0},
    {"seizure", URGENCY_HIGH, 8.0},
    {"confusion", URGENCY_HIGH, 7.0},
    {"stiff neck", URGENCY_HIGH, 7.5},
    {"light sensitivity", URGENCY_HIGH, 7.0},
    {"eye sensitivity", URGENCY_HIGH, 7.0},
    {"blood pressure", URGENCY_HIGH, 7.0},
    {"severe infection", URGENCY_HIGH, 7.5},

    /* ---- MEDIUM -------------------------------------------- */
    {"fever", URGENCY_MEDIUM, 5.0},
    {"cough", URGENCY_MEDIUM, 4.0},
    {"vomiting", URGENCY_MEDIUM, 4.5},
    {"diarrhea", URGENCY_MEDIUM, 4.0},
    {"dizziness", URGENCY_MEDIUM, 4.5},
    {"headache", URGENCY_MEDIUM, 4.0},
    {"fatigue", URGENCY_MEDIUM, 3.5},
    {"body ache", URGENCY_MEDIUM, 4.0},
    {"nausea", URGENCY_MEDIUM, 4.0},
    {"sore throat", URGENCY_MEDIUM, 3.5},
    {"rash", URGENCY_MEDIUM, 4.0},

    /* ---- LOW ----------------------------------------------- */
    {"mild cold", URGENCY_LOW, 2.0},
    {"runny nose", URGENCY_LOW, 2.0},
    {"mild headache", URGENCY_LOW, 2.5},
    {"minor cut", URGENCY_LOW, 1.5},
    {"bruise", URGENCY_LOW, 1.5},

    {NULL, URGENCY_LOW, 0.0} /* sentinel — must be last */
};

/* ── Helpers ────────────────────────────────────────────────── */

/* Convert string to lowercase in-place */
static void str_to_lower(char *s) {
  for (; *s; s++)
    *s = (char)tolower((unsigned char)*s);
}

/* Strip trailing CR and LF (Windows CRLF fix) */
static void strip_crlf(char *s) {
  size_t n = strlen(s);
  while (n > 0 && (s[n - 1] == '\r' || s[n - 1] == '\n'))
    s[--n] = '\0';
}

/*
 * Word-boundary substring check.
 * Returns 1 if `needle` appears as a whole word (or phrase) in `haystack`.
 * Both strings must already be lowercase.
 * The haystack is assumed to have leading and trailing spaces added
 * (we do this in calculateUrgency).
 */
static int contains_word(const char *haystack, const char *needle) {
  /* Build padded needle: " needle " */
  char padded[MAX_SYMPTOM_LEN + 4];
  padded[0] = ' ';
  strncpy(padded + 1, needle, MAX_SYMPTOM_LEN + 1);
  padded[MAX_SYMPTOM_LEN + 2] = '\0';
  strncat(padded, " ", 1);
  return strstr(haystack, padded) != NULL;
}

/* ── Core urgency calculation ───────────────────────────────── */
static UrgencyLevel calculateUrgency(int severity, const char *raw_symptoms) {
  char buf[MAX_SYMPTOM_LEN + 3]; /* room for leading+trailing spaces */
  UrgencyLevel max_level = URGENCY_LOW;
  double total_score = 0.0;
  double severity_factor, weighted_score;
  int i;

  /* Guard against NULL or empty input */
  if (!raw_symptoms || raw_symptoms[0] == '\0') {
    goto severity_only;
  }

  /* Build padded, lowercase copy:  " <symptoms> " */
  buf[0] = ' ';
  strncpy(buf + 1, raw_symptoms, MAX_SYMPTOM_LEN - 1);
  buf[MAX_SYMPTOM_LEN] = ' ';
  buf[MAX_SYMPTOM_LEN + 1] = '\0';
  str_to_lower(buf);

  /* Walk symptom table */
  for (i = 0; SYMPTOM_TABLE[i].keyword != NULL; i++) {
    if (contains_word(buf, SYMPTOM_TABLE[i].keyword)) {
      total_score += SYMPTOM_TABLE[i].weight;
      if (SYMPTOM_TABLE[i].level > max_level)
        max_level = SYMPTOM_TABLE[i].level;
    }
  }

  /*
   * BUG FIX: severity_factor = severity/10.0
   * Old formula (0.5 + factor*0.5) kept minimum at 0.55×,
   * meaning severity=1 still produced 55% of keyword score.
   * New formula: severity=1 → 0.10×, severity=10 → 1.00×
   */
  severity_factor = (double)severity / 10.0;
  weighted_score = total_score * severity_factor;

  /* Score-based escalation (never de-escalates) */
  if (weighted_score >= 8.0 && max_level < URGENCY_CRITICAL)
    max_level = URGENCY_CRITICAL;
  else if (weighted_score >= 6.0 && max_level < URGENCY_HIGH)
    max_level = URGENCY_HIGH;
  else if (weighted_score >= 3.0 && max_level < URGENCY_MEDIUM)
    max_level = URGENCY_MEDIUM;

severity_only:
  /* Hard severity overrides (always applied) */
  if (severity >= 9 && max_level < URGENCY_CRITICAL)
    max_level = URGENCY_CRITICAL;
  else if (severity >= 7 && max_level < URGENCY_HIGH)
    max_level = URGENCY_HIGH;
  else if (severity >= 4 && max_level < URGENCY_MEDIUM)
    max_level = URGENCY_MEDIUM;

  return max_level;
}

/* ── Level → string ─────────────────────────────────────────── */
/* BUG FIX: explicit LOW case, not buried in default */
static const char *level_to_string(UrgencyLevel level) {
  switch (level) {
  case URGENCY_CRITICAL:
    return "CRITICAL";
  case URGENCY_HIGH:
    return "HIGH";
  case URGENCY_MEDIUM:
    return "MEDIUM";
  case URGENCY_LOW:
    return "LOW";
  default:
    return "LOW";
  }
}

/* Priority queue score used for patient ordering */
static int urgency_to_queue_score(UrgencyLevel level, int severity) {
  int base;
  switch (level) {
  case URGENCY_CRITICAL:
    base = 1000;
    break;
  case URGENCY_HIGH:
    base = 700;
    break;
  case URGENCY_MEDIUM:
    base = 400;
    break;
  default:
    base = 100;
    break;
  }
  return base + (severity * 10);
}

/* ── main ───────────────────────────────────────────────────── */
int main(int argc, char *argv[]) {
  long sev_long;
  int severity;
  char *endptr;
  char symptoms[MAX_SYMPTOM_LEN];
  UrgencyLevel level;
  int score;
  int i;
  size_t remaining;

  if (argc < 2) {
    fprintf(stderr,
            "Usage  : %s <severity 1-10> [symptom words...]\n"
            "Example: %s 8 \"chest pain shortness of breath\"\n"
            "Stdin  : echo \"fever cough\" | %s 5\n",
            argv[0], argv[0], argv[0]);
    return EXIT_FAILURE;
  }

  /* BUG FIX: strtol instead of atoi — detects non-numeric input */
  sev_long = strtol(argv[1], &endptr, 10);
  if (endptr == argv[1] || *endptr != '\0') {
    fprintf(stderr, "Error: severity must be a number (1-10), got: '%s'\n",
            argv[1]);
    return EXIT_FAILURE;
  }
  /* Clamp to valid range */
  if (sev_long < 1)
    sev_long = 1;
  if (sev_long > 10)
    sev_long = 10;
  severity = (int)sev_long;

  /* Collect symptom words from argv[2..] or stdin */
  memset(symptoms, 0, sizeof(symptoms));

  if (argc >= 3) {
    /* BUG FIX: track remaining bytes to prevent buffer overflow */
    for (i = 2; i < argc; i++) {
      remaining = MAX_SYMPTOM_LEN - strlen(symptoms) - 1;
      if (remaining == 0)
        break;
      strncat(symptoms, argv[i], remaining);

      if (i < argc - 1) {
        remaining = MAX_SYMPTOM_LEN - strlen(symptoms) - 1;
        if (remaining > 0)
          strncat(symptoms, " ", 1);
      }
    }
  } else {
    /* Read from stdin */
    if (fgets(symptoms, (int)sizeof(symptoms), stdin) != NULL) {
      /* BUG FIX: strip Windows \r\n, not just \n */
      strip_crlf(symptoms);
    }
  }

  /* Run engine */
  level = calculateUrgency(severity, symptoms);
  score = urgency_to_queue_score(level, severity);

  /* Primary output (captured by PHP shell_exec) */
  printf("%s\n", level_to_string(level));

  /* Diagnostics on stderr (not captured by PHP) */
  fprintf(stderr, "[MediCare AI Urgency Engine | Project By Prabind]\n");
  fprintf(stderr, "  Severity   : %d/10\n", severity);
  fprintf(stderr, "  Symptoms   : %s\n", symptoms[0] ? symptoms : "(none)");
  fprintf(stderr, "  Urgency    : %s\n", level_to_string(level));
  fprintf(stderr, "  Queue Score: %d\n", score);

  return EXIT_SUCCESS;
}
