package com.simmanager.collector
import org.junit.Assert.*
import org.junit.Test
class TelemetryTest {
 @Test fun missingConnectionHasNoDeveloperDefault(){assertEquals("",configuredUrl(null));assertEquals("https://private.example",configuredUrl("https://private.example"));assertEquals("",configuredUrl(""))}
 @Test fun unknownRadioIsNotInvented(){assertNull(radioName(0));assertNull(radioName(999));assertEquals("5G NR",radioName(20))}
 @Test fun invalidSavedOriginCannotBecomeAnEndpoint(){for(value in listOf("http://invalid.example","https://u:p@invalid.example","https://valid.example/api"))assertEquals("",configuredUrl(value))}
}
