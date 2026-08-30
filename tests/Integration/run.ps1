$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
function Invoke-Docker([string[]]$Arguments) {
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed: docker $($Arguments -join ' ')" }
}
try {
    Invoke-Docker @('compose','down','-v','--remove-orphans')
    Invoke-Docker @('compose','up','-d','db','wordpress')
    Invoke-Docker @('compose','run','--rm','cli','wp','core','install','--url=http://localhost:8099','--title=MLS','--admin_user=admin','--admin_password=admin','--admin_email=admin@example.test','--skip-email')
    Invoke-Docker @('compose','run','--rm','cli','wp','rewrite','structure','/%postname%/','--hard')
    Invoke-Docker @('compose','run','--rm','cli','wp','plugin','install','elementor','--version=3.32.5','--force','--activate')
    Invoke-Docker @('compose','run','--rm','cli','wp','plugin','activate','wp-multilingual-seo-translator')
    Invoke-Docker @('compose','run','--rm','cli','wp','eval-file','wp-content/plugins/wp-multilingual-seo-translator/tests/Integration/run.php')
    Invoke-Docker @('compose','run','--rm','cli','wp','option','update','elementor_element_cache_ttl','24')
    Invoke-Docker @('compose','run','--rm','cli','wp','rewrite','flush')
    $source = Invoke-WebRequest 'http://localhost:8099/inicio-integral/?seed=1' -UseBasicParsing
    $english = Invoke-WebRequest 'http://localhost:8099/en/integrated-home/?fresh=1' -UseBasicParsing
    foreach ($text in @('Titulo fuente','Parrafo fuente completo','Boton fuente')) {
        if (-not $source.Content.Contains($text)) { throw "Source HTTP response missing: $text" }
    }
    foreach ($text in @('Integrated home','Complete translated heading','Complete translated paragraph','Translated button')) {
        if (-not $english.Content.Contains($text)) { throw "English HTTP response missing: $text" }
    }
    if ($english.Content.Contains('Titulo fuente')) { throw 'English HTTP response leaked source heading.' }
    Write-Host 'PASS: real HTTP source and translated Elementor pages contain all widgets.' -ForegroundColor Green
    Write-Host 'INTEGRATION SUITE PASSED' -ForegroundColor Green
}
finally {
    Invoke-Docker @('compose','down','-v','--remove-orphans')
}
